<?

//also known as
class AKA
{
    public function __construct()
    {
        $this->aka = array();

        //load FP support center names
        $model = new SC();
        $scs = $model->fetchAll();
        foreach($scs as $sc) {
            $this->aka[$sc->footprints_id] = $sc->long_name;
        }

        //lookup FP users
        $model = new Schema();
        $emails = $model->getemail(); 
        foreach($emails as $email) {
            $this->aka[$email->user] = $email->fullname;
        }
    }

    public function lookupName($a)
    {
        if(isset($this->aka[$a])) return $this->aka[$a];
        return null;
    }
}
