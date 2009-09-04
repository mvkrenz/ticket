<?

class AdminController extends BaseController
{ 
    public function init()
    {
        //don't use this - we are really doing 2 things here..
        //1) rendering the real admin page via browser
        //2) accepting request from cron from localhost
    }

    public function indexAction()
    {
        $this->view->submenu_selected = "admin";

        if(!user()->allows("admin")) {
        //if(!in_array(role::$goc_admin, user()->roles)) {
            $this->render("error/access", null, true);
            return;
        }
    }

    private function accesscheck($remote_addr = null)
    {
        //make sure the request originated from localhost
        if($_SERVER["REMOTE_ADDR"] != $remote_addr and !islocal()) {
            //pretend that this page doesn't exist
            $this->getResponse()->setRawHeader('HTTP/1.1 404 Not Found');
            echo "access denided";
            elog("Illegal access from ".$_SERVER["REMOTE_ADDR"]);
            exit;
        }
    }
    public function logrotateAction()
    {
        $this->accesscheck();

        dlog("Writing config file for logrotate...");
        $root = getcwd()."/";
        $statepath = "/tmp/ticket.rotate.state";
        $config = "compress \n".
            $root.config()->logfile. " ". 
            $root.config()->error_logfile. " ". 
            $root.config()->audit_logfile." {\n".
            "   rotate 5\n".
            "   size=50M\n".
            "}";
        $confpath = "/tmp/ticket.rotate.conf";
        $fp = fopen($confpath, "w");
        fwrite($fp, $config);
        
        dlog("running logroate with following config\n$config");
        passthru("/usr/sbin/logrotate -s $statepath $confpath");
    }

    public function groupticketAction()
    {
        $this->accesscheck();

        set_time_limit(120);

        $model = new Tickets();
        $tickets = $model->getrecent();
        header('content-type: text/xml');
        echo "<groups>";

        $grouped = array();
        while(sizeof($tickets) > 1) {
            $master = array_shift($tickets);
            if(in_array($master->mrid, $grouped)) {
                //already in other group..
                continue;
            }

            $master_desc = $this->grabwhatmatters($master);
            $master_size = strlen($master_desc);
            $date = $master->mrupdatedate;
            echo "<group>";
            $this->outputgroup($master, $master_desc, 100); //master get 100% score
            foreach($tickets as $ticket) {
                if(!in_array($ticket->mrid, $grouped)) {
                    $ticket_desc = $this->grabwhatmatters($ticket);
                    $ticket_size = strlen($ticket_desc);
                    similar_text($master_desc, $ticket_desc, $p);
                    $p = round($p, 2);
                    if($p > 40) {
                        $this->outputgroup($ticket, $ticket_desc, $p);
                        $grouped[] = $ticket->mrid;
                    }
                }
            }
            echo "</group>";
        }
        echo "</groups>";

        $this->render("none", null, true);
    }

    private function outputgroup($ticket, $content, $p) 
    {
        echo "<ticket>";
        echo "<id>$ticket->mrid</id>";
        echo "<title>".$this->formattitle($ticket->mrtitle)."</title>";
        echo "<status>".Footprint::parse($ticket->mrstatus)."</status>";
        echo "<dest>$ticket->mrdest</dest>";
        echo "<url>https://oim.grid.iu.edu/gocticket/viewer?id=$ticket->mrid</url>";
        echo "<desc><![CDATA[$content]]></desc>";
        echo "<score>$p</score>";
        echo "</ticket>";
    }

    private function formattitle($title)
    {
        $title = preg_replace('/[^(\x20-\x7F)]*/','', $title);
        $title = htmlentities($title, ENT_QUOTES);
        return $title;
    }

    private function grabwhatmatters($ticket)
    {
        $desc_all = $ticket->mralldescriptions;

        //use the first description
        $desc_sections = preg_split("/<! [^>]* >/", $desc_all, 2, PREG_SPLIT_NO_EMPTY);
        $desc = trim($desc_sections[0]);

        //clean up some html ness
        $desc = html_entity_decode($desc, ENT_QUOTES);
        //$desc = preg_replace("/&[a-z]+;/", "?", $desc);

        //truncate if it's too long
        $desc = substr($desc, 0, 1000);

        //remove standard GOC signature
        $sig = "OSG Grid Operations Center
goc@opensciencegrid.org, 317-278-9699
Visit the OSG Support Page:
http://www.opensciencegrid.org/ops
RSS: http://www.grid.iu.edu/news";
        $desc = str_replace($sig, "", $desc);

        //remove Thank you note..
        $sig = "/\nThank you(.|\n)*/i";
        $desc = preg_replace($sig, "", $desc);
        $sig = "/\nThanks(.|\n)*/i";
        $desc = preg_replace($sig, "", $desc);

        //remove meta info
        $meta = config()->metatag;
        $meta = str_replace("[", "\[", $meta);
        $meta = str_replace("]", "\]", $meta);
        $sig = "/\n$meta(.|\n)*/";
        slog($sig);
        $desc = preg_replace($sig, "", $desc);

        //remove GGUS Info
        $sig = "/Other GGUS Ticket Info:(.|\n)*/"; //old format
        $desc = preg_replace($sig, "", $desc);
        $sig = "/\n\[Other GGUS Ticket Info\](.|\n)*/"; //new format
        $desc = preg_replace($sig, "", $desc);

        return $desc;
    }

    public function ggussubmitAction()
    {
        $client_dns = "tick-indy.globalnoc.iu.edu";
        $ip = system("host $client_dns | head -n 1 | grep -Eo '[[:digit:]]+(\.[[:digit:]]+)+'");
        $this->accesscheck($ip);

        if(isset($_REQUEST["xml"])) {
            try {
                //construct footprint object from xml
                $xml_content = $_REQUEST["xml"];
                slog("Received GGUS XML");
                slog($xml_content);

                require_once("lib/ggusticket.php");
                $footprint = ggus2footprint($xml_content);
                if($footprint !== null) {
                    slog("Created ggus footprint ticket object (to be submitted)");

                    //submit
                    slog("Submitting ggus ticket");
                    $mrid = $footprint->submit();
                    slog("GGUS Ticket insert / update success - FP Ticket ID $mrid");
                }
            } catch(exception $e) {
                $this->sendErrorEmail($e);
            }
        }
        $this->render("none", null, true);
    }

}
