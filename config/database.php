<?php
class Database
{
    private $host = "127.0.0.1";
    private $database_name = "listhub";
    private $username = "root";
    private $password = "";
    public $conn;

    public function getConnection()
    {
        $this->conn = mysqli_connect($this->host, $this->username, $this->password);
        mysqli_select_db($this->conn, $this->database_name);
        mysqli_query($this->conn, "set names 'utf8mb4'");
        mysqli_query($this->conn, "SET SESSION wait_timeout = 100");
        mysqli_query($this->conn, "SET SESSION interactive_timeout = 100");

        return $this->conn;
    }
}
