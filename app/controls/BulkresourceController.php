<?

class BulkresourceController extends BaseController
{
    function init()
    {
        $this->view->submenu_selected = "admin";

        $model = new Resource();
        $kv = array();
        foreach($model->fetchAll() as $resource) {
            $id = $resource->id;
            $name  = $resource->name;
            $fqdn = $resource->fqdn;
            $kv[$id] = "$name ($fqdn)";
        }
        Zend_Registry::set("resource_kv", $kv);
        Zend_Registry::set("resource_ids", array());
    }

    public function indexAction()
    {
        if(!user()->allows("admin")) {
            $this->render("error/access", null, true);
            return;
        }

        $form = $this->getForm();

        //redo is set when user hit "cancel" button in the preview page
        if(isset($_REQUEST["redo"])) {
            //populate data from session
            $session = new Zend_Session_Namespace('bulkresource');

            Zend_Registry::set("resource_ids", $session->resource_ids);
            Zend_Registry::set("passback_ccs", $session->cc);
            $form->getElement("title")->setValue($session->title);
            $form->getElement("template")->setValue($session->template);
            $form->getElement("name")->setValue($session->name);
            $form->getElement("email")->setValue($session->email);
            $form->getElement("phone")->setValue($session->phone);
            $form->getElement("vo_id")->setValue($session->vo_id);
        }
        $this->view->form = $form;
        $this->render();
    }

    public function getForm()
    {
        $form = $this->initForm("bulkresource");

        $title = new Zend_Form_Element_Text('title');
        $title->setLabel("Title");
        $title->setRequired(true);
        $title->setValue("Resource Issue for \$RESOURCE_NAME");
        $form->addElement($title);

        $template = new Zend_Form_Element_Textarea('template');
        $template->setLabel("Description Template");
        $template->setRequired(true);
        $template->setValue("Dear \$PRIMARY_ADMIN_NAME,

Something is screwed up on resource \$RESOURCE_NAME.

Please fix it.

Thank You,
OSG Grid Operations Center (GOC)
Email/Phone: goc@opensciencegrid.org, 317-278-9699
GOC Homepage: http://www.opensciencegrid.org/ops
RSS Feed: http://osggoc.blogspot.com");
        $form->addElement($template);
        return $form;
    }

    public function previewAction() 
    {
        if(!user()->allows("admin")) {
            $this->render("error/access", null, true);
            return;
        }

        $resource_ids = @$_REQUEST["list"];

        $form = $this->getForm();
        if($form->isValid($_POST)) {
            //do some additional validation - that wasn't checked via Zend form
            if(!isset($_REQUEST["list"])) {
                $this->view->errors = "Please specify list of resource to send tickets to.";
                $this->view->form = $form;
                $this->render("index");
                return;
            }

            $title = $_REQUEST["title"];
            $template = $_REQUEST["template"];

            //store various data into session - so that submiAction can use it (also used by createTickets)
            $session = new Zend_Session_Namespace('bulkresource');
            $resource_ids = $session->resource_ids = $resource_ids;
            $session->title = $title;
            $session->template = $template;
            if(isset($_REQUEST["cc"])) {
                $session->cc = $_REQUEST["cc"];
            }
            $session->name = $form->getValue("name");
            $session->email = $form->getValue("email");
            $session->phone = $form->getValue("phone");
            $session->vo_id = $form->getValue("vo_id");

            //all good... construct ticket & send to preview
            $tickets = $this->createTickets($resource_ids, $title, $template);
            $preview = array();
            foreach($tickets as $rname=>$ticket) {
                $preview[$rname] = $ticket->prepareParams();
            }
            $this->view->preview = $preview;
        } else {
            //selected resources are sent back through registry - since it's not controlled via zend form
            Zend_Registry::set("resource_ids", $resource_ids);

            //cc field is also non-zend, but BaseController::initForm pulls it from $_POST["cc"]

            $this->view->errors = "Please correct the following issues.";
            $this->view->form = $form;
            $this->render("index");
        }

        return $tickets;
    }

    function createTickets($resource_ids, $title, $template) 
    {
        $model = new Resource();
        $resources = $model->fetchAllGroupByID();
        $prac_model = new PrimaryResourceAdminContact();

        $tickets = array();
        foreach($resource_ids as $rid=>$ignore) {
            $resource = $resources[$rid];

            $prac = $prac_model->fetch($rid);
            $mytemplate = $this->applyTemplate($template, $resource, $prac);
            $mytitle = $this->applyTemplate($title, $resource, $prac);

            $ticket = $this->createTicket($mytitle, $mytemplate, $resource);
            $tickets[$resource->name] = $ticket;
        }
        return $tickets;
     }

    public function submitAction() 
    {
        if(!user()->allows("admin")) {
            $this->render("error/access", null, true);
            return;
        }

        $session = new Zend_Session_Namespace('bulkresource');
        $resource_ids = $session->resource_ids;
        $title = $session->title;
        $template = $session->template;

        $tickets = $this->createTickets($resource_ids, $title, $template);

        $success = array();
        $failed = array();
        foreach($tickets as $rname=>$ticket) {
            try {
                $mrid = $ticket->submit();
                $success[$rname] = $mrid;
            } catch(exception $e) {
                $this->sendErrorEmail($e);
                $failed[$rname] = $e;
            }
        }
        $this->view->success = $success;
        $this->view->failed = $failed;

        //clear session
        $session->resource_ids = null;
    }

    private function getFPAgent($name)
    {
        $model = new Schema();
        $users = $model->getusers();
        foreach($users as $id=>$fpname) {
            if($fpname == $name) {
                return $id;
            }
        }
        return null;
    }

    function createTicket($title, $desc, $resource) 
    {
        $name = user()->getPersonName();
        $session = new Zend_Session_Namespace('bulkresource');

        $footprint = new Footprint();

        $footprint->setName($session->name);
        $footprint->setEmail($session->email);
        $footprint->setOfficePhone($session->phone);
        $footprint->setOriginatingVO($session->vo);

        //set VO
        $void = $session->vo_id;
        if($void == -1) {
            $footprint->addMeta("Submitter doesn't know the VO he/she belongs.\n");
        } else {
            $vo_model = new VO();
            $info = $vo_model->get($void);
            if($info->footprints_id === null) {
                $footprint->addMeta("Submitter's VO is ".$info->name. " but its footprints_id is not set in OIM. Please set it.");
            } else {
                $footprint->setOriginatingVO($info->footprints_id);
            }
        }

        //process CC
        if(isset($session->cc)) {
            $ccs = $session->cc;
            foreach($ccs as $cc) {
                $cc = trim($cc);
                if($cc != "") {
                    $footprint->addCC($cc);
                }
            }
        }

        $footprint->addDescription($desc);
        $footprint->setTitle($title);

        //set submitter to the ticket submitter's name ONLY IF the user is registered at FP - otherwise FP throws up
        $agent = $this->getFPAgent($name);
        if($agent !== null) {
            $footprint->setSubmitter($agent);
        } else {
            $footprint->addDescription("\n\n-- by $name");
            $footprint->addMeta("Submitter DN: ".user()->getDN());
            //$footprint->addMeta("Real Submitter: $name (not a registered Footprint Agent - using default submitter)\n");
        }

        //lookup service center
        $resource_id = $resource->id;
        $rs_model = new ResourceSite();
        $resource_name = $resource->name;
        $resource_group_id = $resource->resource_group_id;
        $resource_group_model = new ResourceGroup();
        $resource_group = $resource_group_model->fetchByID($resource_group_id);

        //set description destination vo, assignee
        $footprint->addMeta("Resource on which user is having this issue: ".$resource_name."($resource_id)\n");
        $footprint->setMetadata("ASSOCIATED_RG_ID", $resource_group_id);
        $footprint->setMetadata("ASSOCIATED_RG_NAME", $resource_group->name);
        $footprint->setMetadata("ASSOCIATED_R_ID", $resource_id);
        $footprint->setMetadata("ASSOCIATED_R_NAME", $resource_name);

        $void = $footprint->setDestinationVOFromResourceID($resource_id);
        if($void) {
            $footprint->setMetadata("ASSOCIATED_VO_ID", $void);
        }

        $sc_id = $rs_model->fetchSCID($resource_id);
        if(!$sc_id) {
            $scname = "OSG-GOC";
            $footprint->addMeta("Couldn't find the support center that supports this resource. Please see finderror page for more detail.\n");
        } else {
            //lookup SC name form sc_id
            $sc_model = new SC;
            $sc = $sc_model->get($sc_id);
            $scname = $sc->footprints_id;
            $footprint->setMetadata("SUPPORTING_SC_ID", $sc_id);
        }

        if($footprint->isValidFPSC($scname)) {
            $footprint->addAssignee($scname);
            $footprint->addMeta("Assigned support center: $scname which supports this resource\n");
        } else {
            $footprint->addMeta("Couldn't add assignee $scname since it doesn't exist on FP yet.. (Please sync!)\n");
            elog("Couldn't add assignee $scname since it doesn't exist on FP yet.. (Please sync!)\n");
        }
        $footprint->addPrimaryAdminContact($resource_id);

        return $footprint;
    }

    function applyTemplate($template, $resource, $prac) {
        $desc = $template;
        $desc = str_replace("\$RESOURCE_NAME", $resource->name, $desc);
        $desc = str_replace("\$RESOURCE_FQDN", $resource->fqdn, $desc);
        $desc = str_replace("\$PRIMARY_ADMIN_NAME", $prac->name, $desc);
        return $desc;
    }

} 
