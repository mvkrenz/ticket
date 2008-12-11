<?

class GocadminController extends BaseController
{ 
    public function init()
    {
        $this->view->submenu_selected = "gocadmin";
        //only goc users are allowed
        if(!in_array(role::$goc_admin, user()->roles)) {
            $this->render("error/404", null, true);
            return;
        }
    }
    public function indexAction() 
    { 
        $this->render();
    }
} 
