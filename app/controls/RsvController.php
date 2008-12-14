<?

class RsvController extends BaseController
{ 
    public function init()
    {
        $this->view->submenu_selected = "open";
    }
    public function indexAction() 
    { 
        $this->view->form = $this->getForm();
        $this->render();
    }

    public function submitAction()
    {
        $form = $this->getForm();
        $issue_element = $this->getIssueElement($form);
        $issue_element->setRequired(true);

        if($form->isValid($_POST)) {
            $footprint = $this->initSubmit($form);
            $footprint->setFirstName("OSG-GOC");
            $footprint->setEmail("goc@opensciencegrid.org");
            $footprint->setOriginatingVO("MIS");

            //lookup service center
            //$resource_id = $form->getValue($issue_element_name);
            $resource_id = $issue_element->getValue();
            $rs_model = new ResourceSite();
            $resource = $rs_model->fetch($resource_id);

            //set description destination vo, assignee
            $footprint->addMeta("Resource where user is having this issue: ".$resource->resource_name."($resource_id)\n");
            $footprint->setTitle("RSV issue on ".$resource->resource_name);

            $template = $form->getValue('detail');
            $template = str_replace("__RESOURCE_NAME__", $resource->resource_name, $template);
            $footprint->addDescription($template);

            //lookup SC name
            $sc_model = new SC;
            $sc = $sc_model->get($resource->sc_id);
            $scname = $sc->footprints_id;
            $footprint->addMeta("This resource is supported at SC:$scname. Please reset destination VO accordingly.\n");

            if($footprint->isValidFPSC($scname)) {
                $footprint->addAssignee($scname);
            } else {
                $footprint->addMeta("Couldn't add assignee $scname since it doesn't exist on FP yet.. (Please sync!)\n");
            }

            //find primary resource admin email
            $prac_model = new PrimaryResourceAdminContact();
            $prac = $prac_model->fetch($resource_id);
            $footprint->addCC($prac->primary_email);
            $footprint->addMeta("Primary Admin for ".$resource->resource_name." is ".$prac->first_name." ".$prac->last_name." and has been CC'd regarding this ticket.\n");
            $footprint->addMeta("Primary Admin Info\n".$this->dumprecord($prac)."\n");

            $footprint->addAssignee("RSV-Ops");

            try 
            {
                $mrid = $footprint->submit();
                $this->view->mrid = $mrid;
                $this->render("success", null, true);
            } catch(exception $e) {
                $this->sendErrorEmail($e);
                $this->render("failed", null, true);
            }
        } else {
            $this->view->errors = "Please correct following issues.";
            $this->view->form = $form;
            $this->render("index");
        }
    }

    public function getIssueElement($form)
    {
        //make one of resource_issue field required based on resource_type selection
        $resource_type = $_REQUEST["resource_type"];
        $issue_element_name = "resource_id_with_issue_$resource_type";
        $issue_element = $form->getElement($issue_element_name);
        return $issue_element;
    }

    private function getForm()
    {
        $form = $this->initForm("rsv", false); //false is for no-yourinfo

        $element = new Zend_Form_Element_Select('resource_type');
        $element->setLabel("I am having this issue in following resource");
        $element->setRequired(true);
        $gridtype_model = new GridType;
        $gridtypes = $gridtype_model->fetchAll();
        foreach($gridtypes as $gridtype) {
            $element->addMultiOption($gridtype->grid_type_id, $gridtype->description);
        }
        $form->addElement($element);

        $element = new Zend_Form_Element_Select('resource_id_with_issue_1');
        $element->addMultiOption(null, "(Please Select)");
        $resource_model = new Resource;
        $resources = $resource_model->fetchAll(1);
        foreach($resources as $resource) {
            $element->addMultiOption($resource->resource_id, $resource->name);
        }
        $form->addElement($element);

        $element = new Zend_Form_Element_Select('resource_id_with_issue_2');
        $element->addMultiOption(null, "(Please Select)");
        $resource_model = new Resource;
        $resources = $resource_model->fetchAll(2);
        foreach($resources as $resource) {
            $element->addMultiOption($resource->resource_id, $resource->name);
        }
        $form->addElement($element);

        $detail = new Zend_Form_Element_Textarea('detail');
        $detail->setLabel("Template");
        $detail->setRequired(true);
        $detail->setValue($this->getTemplate());
        $form->addElement($detail);

        $element = new Zend_Form_Element_Submit('submit_button');
        $element->setLabel("Send Email");
        $form->addElement($element);

        return $form;
    }

    private function getTemplate()
    {
        return "The central RSV collector has not received metric records for
__RESOURCE_NAME__. Please run the following profiler script, send us
that tarball (or make it accessible off the web), and then restart
RSV? Thanks!

Download Profiler:

wget http://vdt.cs.wisc.edu/scot/rsv-profiler
/bin/sh rsv-profiler
# provide access to tarball to rsv-dev by email or via web

To Restart:

cd $VDT_LOCATION
vdt-control --off osg-rsv condor-cron
vdt-control --on condor-cron osg-rsv

Hopefully that'll resume the RSV client. To view RSV probe and
consumer jobs ...

condor_cron_q

And you can look at:

$VDT_LOCATION/osg-rsv/logs/consumers/*.log
(and $VDT_LOCATION/osg-rsv/logs/probes/*.log)

to see there are any indications of any errors.

Let me know if you have any questions.

Thanks,
RSV Dev Team";
    }
} 