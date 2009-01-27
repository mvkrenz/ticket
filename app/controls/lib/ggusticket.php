<?php

function ggus2footprint($xml_content)
{
    $xml = new SimpleXMLElement($xml_content);
    $footprint = new Footprint;

    //TODO - if I don't set submitter parameter, the web api will say "An error page was displayed to the Web Services user.  Details: Submitter 'goc' is not a valid user."
    $footprint->setSubmitter("ggus");

    $node = "GHD_Request-ID";
    $id = (int)$xml->$node;
    $footprint->setOriginatingTicketNumber($id);

    //check if the ticket is already in FP
    $model = new Tickets(); 
    dlog("searching for $id");
    $orig = $model->getoriginating($id);
    if(count($orig) == 0) {
        ///////////////////////////////////////////////////////////////////////
        // Insert
        ///////////////////////////////////////////////////////////////////////
        slog("inserting new FP ticket $id");
        $footprint->addDescription($xml->GHD_Description);
        $desc = "\n
[Other GGUS Ticket Info]
Date GGUS Ticket Opened: $xml->GHD_Date_Time_Of_Problem
GGUS Ticket ID:          $id
Short Description:       $xml->GHD_Short_Description
Solution:                $xml->GHD_Short_Solution
Experiment:              $xml->GHD_Experiment
Affected Site:           $xml->GHD_Affected_Site
Responsible Unit:        $xml->GHD_Responsible_Unit";

        $footprint->addDescription($desc);

        //We need to asssign ggus so that when someone reply to FP ticket, it will be forwared to GGUS.
        $footprint->addAssignee("ggus");

        //contact info
        $fullname = split(" ", $xml->GHD_Name);
        $footprint->setFirstName($fullname[0]);
        $footprint->setLastName($fullname[1]);
        $footprint->setOfficePhone((string)$xml->GHD_Phone);
        $node = "GHD_E-Mail";
        $footprint->setEmail((string)$xml->$node);

        //title
        $title = str_replace("\n", "", $xml->GHD_Short_Description);
        $footprint->setTitle($title);

        $footprint->setOriginatingVO("Ops"); 
        $footprint->setNextAction("Operator Review");

        //lookup resource from resource name
        if(isset($xml->GHD_Affected_Site)) {
            dlog("setting affected resource info");

            $model = new Resource();
            $name = (string)$xml->GHD_Affected_Site;
            $resource_id = $model->fetchID($name);
            if($resource_id === false) {
                $footprint->addMeta("Resource '$name' as specified in the GHD_Affected_Site field couldn't be found in OIM.");
            } else {
                $rs_model = new ResourceSite();
                $resource = $rs_model->fetch($resource_id);

                //set description destination vo, assignee
                $footprint->addMeta("Resource where user is having this issue: ".$name."($resource_id)\n");

                //lookup SC name
                if($resource === false) {
                    $scname = "OSG-GOC";
                    $footprint->addMeta("Couldn't find the SC associated with this resource. Please see finderror page for more detail.");
                } else {
                    $scname = $footprint->setDestinationVOFromSC($resource->sc_id);
                }

                if($footprint->isValidFPSC($scname)) {
                    $footprint->addAssignee($scname);
                } else {
                    $footprint->addMeta("Couldn't add assignee $scname since it doesn't exist on FP yet.. (Please sync!)\n");
                }
                $footprint->addPrimaryAdminContact($resource_id);
            }
        }
    } else {
        ///////////////////////////////////////////////////////////////////////
        // Update
        ///////////////////////////////////////////////////////////////////////
        $fpid = $orig[0]->mrid;
        slog("Originating ticket $id already exists in FP as $fpid . Doing Update..");
        $footprint->setID($fpid); 

        //only set description field to be updated
        //I don't know which one of these fields really contain the update-description..
        //$footprint->addDescription($xml->GHD_Public_Diary);
        $footprint->addDescription($xml->GHD_Diary_Of_Steps); 
        $footprint->addDescription($xml->GHD_Internal_Diary);
    }

    return $footprint;
}

/*
GGUS Ticket Fields explanation
<Ticket>
    <GHD_Request-ID>GGUS Request-ID</GHD_Request-ID>
    <GHD_Loginname>DN string or login name of ticket submitter</GHD_Loginname>
    <GHD_Name>name of ticket submitter</GHD_Name>
    <GHD_E-Mail>email address of ticket submitter</GHD_E-Mail>
    <GHD_Phone>phone number of ticket submitter</GHD_Phone>
    <GHD_Experiment>VO affected by problem reported</GHD_Experiment>
    <GHD_Responsible_Unit>Support Unit to which ticket is assigned</GHD_Responsible_Unit>
    <GHD_Status>status of ticket</GHD_Status>
    <GHD_Priority>priority of ticket</GHD_Priority>
    <GHD_Short_Description>short description of problem (max.255 chars)</GHD_Short_Description>
    <GHD_Description>detailed description of problem (max. 4000 chars)</GHD_Description>
    <GHD_Experiment_Specific_Problem>is problem VO specific? Yes|No</GHD_Experiment_Specific_Problem>
    <GHD_Type_Of_Problem>type of problem reported</GHD_Type_Of_Problem>
    <GHD_Date_Time_Of_Problem>date and time the problem occured</GHD_Date_Time_Of_Problem>
    <GHD_Diary_Of_Steps>mail replies from user and comments on automatic updates done by the system</GHD_Diary_Of_Steps>
    <GHD_Public_Diary>comment sent to the user by mail</GHD_Public_Diary>
    <GHD_Short_Solution>short solution of problem (max.255 chars)</GHD_Short_Solution>
    <GHD_Detailed_Solution>detailed solution of problem (max. 4000 chars)</GHD_Detailed_Solution>
    <GHD_Internal_Diary>internal comment not shown to the user</GHD_Internal_Diary>
    <GHD_Origin_ID>ID of ticket in a different system than GGUS; these tickets are routed from any other system via GGUS to OSG</GHD_Origin_ID>
    <GHD_Last_Modifier>name of person who modified the ticket last</GHD_Last_Modifier>
    <GHD_Affected_Site>name of the affected site</GHD_Affected_Site>
</Ticket>

Sample Ticket

<GHD_Request-ID>606</GHD_Request-ID>
<GHD_Loginname>/O=GermanGrid/OU=FZK/CN=Guenter
Grein</GHD_Loginname>
<GHD_Name>Guenter Grein</GHD_Name>
<GHD_E-Mail>guenter.grein@iwr.fzk.de</GHD_E-Mail>
<GHD_Phone></GHD_Phone>
<GHD_Experiment>atlas</GHD_Experiment>
<GHD_Responsible_Unit>OSG</GHD_Responsible_Unit>
<GHD_Status>assigned</GHD_Status>
<GHD_Priority>less urgent</GHD_Priority>
<GHD_Short_Description>new test</GHD_Short_Description>
<GHD_Description>new test</GHD_Description>

<GHD_Experiment_Specific_Problem>No</GHD_Experiment_Specific_Problem>
<GHD_Type_Of_Problem>GGUS Internal Tests</GHD_Type_Of_Problem>
<GHD_Date_Time_Of_Problem>2009-01-12
14:12:17</GHD_Date_Time_Of_Problem>
<GHD_Diary_Of_Steps></GHD_Diary_Of_Steps>
<GHD_Public_Diary></GHD_Public_Diary>
<GHD_Short_Solution></GHD_Short_Solution>
<GHD_Detailed_Solution></GHD_Detailed_Solution>
<GHD_Internal_Diary></GHD_Internal_Diary>
<GHD_Origin_ID></GHD_Origin_ID>
<GHD_Last_Modifier>Paul Mustermann</GHD_Last_Modifier>
<GHD_Affected_Site>BNL_ATLAS_1</GHD_Affected_Site>

<GHD_Experiment>atlas</GHD_Experiment>
<GHD_Affected_Site>BNL_ATLAS_1</GHD_Affected_Site>
<GHD_Responsible_Unit>OSG</GHD_Responsible_Unit>
</Ticket>
*/
