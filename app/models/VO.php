<?

class VO
{
    public function sql()
    {
        return "select * from virtualorganization where active = 1 and disable = 0";
    }
    public function fetchAll()
    {
        $sql = $this->sql()." order by short_name";
        $vos = db()->fetchAll($sql);
        return $vos;
    }
    public function get($void) 
    {
        $sql = $this->sql()." and vo_id = $void";
        return db()->fetchRow($sql);
    }
}

?>
