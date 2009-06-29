<?

class NotifyController extends BaseController
{ 
    public function init()
    {
        if(isset($_REQUEST["security"])) {
            $this->view->pagename = "Create Security Notification";
        } else {
            $this->view->pagename = "Send Notification Email/RSS";
        }
        
        $this->view->submenu_selected = "open";
    }

    public function indexAction() 
    { 
        if(!user()->allows("notify")) {
            $this->render("error/access", null, true);
            return;
        }

        $this->view->form = $this->getForm();
        $this->render();
    }

    public function submitAction()
    {
        if(!user()->allows("notify")) {
            $this->render("error/access", null, true);
            return;
        }

        $do_rss = false;
        if($_REQUEST["rss"] == 1) {
            $do_rss = true;
        }

        $form = $this->getForm();
        if($form->isValid($_POST)) {
            $this->view->detail = "<h2>Process Detail</h2><br/>";

            //if this is for security ticket, create footprint ticket with security type with no sc assignee
            $security_mrid = null;
            if(isset($_REQUEST["security"])) {
                $footprint = $this->initSubmit($form);
                $footprint->setTitle("[OSG-SEC-NOTIFY-".date("Y-m-d")."] ".$form->getValue('subject'));
                $footprint->addDescription($form->getValue('body'));
                $footprint->setTicketType("Security");
                $footprint->addAssignee("rquick", true);
                $footprint->addAssignee("maltunay");
                $footprint->setName("GOC");
                $footprint->setOfficePhone("317-278-9699");
                $footprint->setEmail("goc@opensciencegrid.org");

                try
                {
                    $security_mrid = $footprint->submit();

                    $this->view->detail .= "<br/><h3>Following Ticket(ID:$security_mrid) has been submitted</h3><br/>";
                    $this->view->detail .= "<pre>".print_r($footprint, true)."</pre>";

                } catch(exception $e) {
                    $this->sendErrorEmail($e);
                    $this->render("failed", null, true);
                }
            }

            //construct security email object
            $e = new SecurityEmail();
            if($form->getValue('rsecurity')) {
                $e->addResourceSecurityAddresses();
            }
            if($form->getValue('vsecurity')) {
                $e->addVOSecurityAddresses();
            }
            if($form->getValue('ssecurity')) {
                $e->addSCSecurityAddresses();
            }
            if($form->getValue('support')) {
                $e->addSupportAddresses();
            }
            if($form->getValue('general')) {
                $e->addAddress("osg-general@opensciencegrid.org");
            }
            if($form->getValue('operations')) {
                $e->addAddress("osg-operations@opensciencegrid.org");
            }
            if($form->getValue('sites')) {
                $e->addAddress("osg-sites@opensciencegrid.org");
            }
            if(config()->debug) {
                $e->addAddress("hayashis@indiana.edu");
            }

            $model = new Person();
            $person_id = $form->getValue('email_from');
            if($person_id != "") {
                $person = $model->fetchPerson($person_id);
                $e->setFrom($person->name." <".$person->primary_email.">");
            } else {
                if(isset($_REQUEST["security"])) {
                    $e->setFrom(config()->email_from_security);
                } else {
                    $e->setFrom(config()->email_from);
                }
            }

            $e->setTo('goc@opensciencegrid.org');

            if(isset($_REQUEST["security"])) {
                $e->setSubject("OSG-SEC-NOTIFY-".date("Y-m-d"));
            } else {
                $subject = $form->getValue('subject');
                $ticket_id = $form->getValue('ticket');
                if($ticket_id != "") { 
                    $subject .= " - GOC Ticket # " . $ticket_id;
                }
                $e->setSubject($subject);
            }

            if(isset($_REQUEST["security"])) {
                $e->setBody($this->getSecurityBody($security_mrid));
            } else {
                $body = $form->getValue('body');
                $sig = $form->getValue('sig');
                if($ticket_id != "") { 
                    $body .= "\n\nPlease see ticket $ticket_id at:\nhttps://ticket.grid.iu.edu/goc/viewer?id=$ticket_id\n";
                }
                $e->setBody($body."\n".$sig);
            }

            try
            {
                if(config()->simulate) {
                    echo "<h2>Simulation</h2>";
                    echo $e->dump();
                    $this->render("none", null, true);
                    if($do_rss) {
                        echo "RSS feed will be added";
                    }
                } else {
                    $e->send();

                    $this->view->detail .= "<br/><h3>Following Email has been sent</h3><br/>";
                    $this->view->detail .= $e->dump();

                    if($do_rss) {
                        $r = new RSSFeed();
                        $r->insert($subject, $ticket_id, $body);

                        $this->view->detail .= "<br/><h3>RSS Feed has been generated</h3><br/>";
                    }
                    $this->render("processed", null, true);
                }
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

    private function getForm()
    {
        if(isset($_REQUEST["security"])) {
            $form = $this->initForm("notify", false, "security"); //false - no contact information necessary
        } else {
            $form = $this->initForm("notify", false);
        }

        $e = new Zend_Form_Element_Checkbox('rsecurity');
        $form->addElement($e);
        $e = new Zend_Form_Element_Checkbox('vsecurity');
        $form->addElement($e);
        $e = new Zend_Form_Element_Checkbox('ssecurity');
        $form->addElement($e);
        $e = new Zend_Form_Element_Checkbox('support');
        $form->addElement($e);
        $e = new Zend_Form_Element_Checkbox('general');
        $form->addElement($e);
        $e = new Zend_Form_Element_Checkbox('sites');
        $form->addElement($e);
        $e = new Zend_Form_Element_Checkbox('operations');
        $form->addElement($e);

        $e = new Zend_Form_Element_Select('email_from');
        $e->setLabel("Sender Address");
        if(isset($_REQUEST["security"])) {
            $e->addMultiOption(null, config()->email_from_security);
        } else {
            $e->addMultiOption(null, config()->email_from);
        }
        $model = new Person();
        $contacts = $model->fetchGOC(4);
        foreach($contacts as $contact) {
            $e->addMultiOption($contact->id, $contact->name." <".$contact->primary_email.">");
        }
        $form->addElement($e);

        $e = new Zend_Form_Element_Text('subject');
        if(isset($_REQUEST["security"])) {
            $e->setLabel("Subject that will be used in security ticket that is generated");
        } else {
            $e->setLabel("Subject");
        }
        $e->setRequired(true);
        $form->addElement($e);

        if(!isset($_REQUEST["security"])) {
            $e = new Zend_Form_Element_Text('ticket');
            $e->setLabel("Footprint Ticket ID");
            $e->addValidator(new Zend_Validate_Int()); //ture for allowWhiteSpace
            $e->setDescription("* Optional");
            $e->addDecorator("description");
            $e->setRequired(false);
            $form->addElement($e);
        }

        $detail = new Zend_Form_Element_Textarea('body');
        $detail->addValidator(new Zend_Validate_StringLength(1, 1024*1024*16)); //max 16M for MEDIUMTEXT
        if(isset($_REQUEST["security"])) {
            $detail->setLabel("Content that will be used in security ticket that is generated");
        } else {
            $detail->setLabel("Content");
        }
        $detail->setRequired(true);
        $form->addElement($detail);

        if(!isset($_REQUEST["security"])) {
            $e = new Zend_Form_Element_Textarea('sig');
            $e->setLabel("Email Signature");
            $e->setRequired(true);
            $e->setValue($this->getGOCSigTemplate());
            $form->addElement($e);

            $e = new Zend_Form_Element_Checkbox('rss');
            $e->setLabel("Publish to RSS");
            $form->addElement($e);
        }

        $submit = new Zend_Form_Element_Submit('submit_button');
        $submit->setLabel("Submit");
        $form->addElement($submit);

        return $form;
    }
    private function getSecurityBody($mrid)
    {
        return "
A new security notification has been posted.

You are receiving this message because you are listed as a security contact for an OSG resource or VO or support center. Assuming you are registered on OIM, and are authorized to view security tickets, please open the following URL for further information:
https://ticket.grid.iu.edu/goc/viewer?id=$mrid

Please contact security@opensciencegrid.org if you have any questions or concerns.

This message is digitally signed. We encourage you to verify the digital signatures on OSG security announcements according to:
https://twiki.grid.iu.edu/bin/view/Security/SecureEmail

Sincerely,
OSG Security Team
";
    }

    private function getGOCSigTemplate()
    {
        return "
Please submit problems, requests, and questions at:
https://ticket.grid.iu.edu/goc

Thank You,
OSG Grid Operations Center (GOC)
Email/Phone: goc@opensciencegrid.org, 317-278-9699
GOC Homepage: http://www.opensciencegrid.org/ops
RSS Feed: http://osggoc.blogspot.com
";
    }
} 

class SecurityEmail
{
    public function __construct()
    {
        $this->h_email = array();
        $this->bcc = "";
        $this->pa_model = new PrimaryAddress();
        $this->from = config()->email_from_security;
    }

    public function setFrom($val) { $this->from = $val; }
    public function setTo($val) { $this->to = $val; }
    public function setSubject($val) { $this->subject = $val; }
    public function setBody($val) { $this->body = $val; }

    public function addAddress($email)
    {
        if (!isset($this->h_email[$email])) {
            $this->bcc .= $email . ", "; 
            $this->h_email[$email]=1;
        }
    }
    private function addAddresses($recs)
    {
        foreach($recs as $rec) {
            $email = $rec->primary_email;
            $this->addAddress($email);
        }
    }

    public function addResourceSecurityAddresses()
    {
        $recs = $this->pa_model->get_resource_security();
        $this->addAddresses($recs);
    }

    public function addVOSecurityAddresses()
    {
        $recs = $this->pa_model->get_vo_security();
        $this->addAddresses($recs);
    }

    public function addSCSecurityAddresses()
    {
        $recs = $this->pa_model->get_sc_security();
        $this->addAddresses($recs);
    }

    public function addSupportAddresses()
    {
        $recs = $this->pa_model->get_sc();
        $this->addAddresses($recs);
    }
    public function dump()
    {
        $out = "";
        $out .= "<hr>To: ".$this->to."\n\n";
        $out .= "<hr>From: ".htmlentities($this->from)."\n\n";
        $out .= "<hr>Subject: ".$this->subject."\n\n";
        $out .= "<hr>BCC: ".$this->bcc."\n\n";
        $out .= "<hr>Body:<pre>".$this->body."</pre>\n\n";
        $out .= "<hr>\n\n";
        return $out;
    }

    public function send()
    {
        signedmail($this->to, $this->subject, $this->body, "Bcc: ".$this->bcc);

        slog("[submit] Signed notification email sent with following content --------------------------");
        slog(print_r($this, true));
    }
}
