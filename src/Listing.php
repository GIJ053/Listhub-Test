<?php

namespace Src;

require '../vendor/autoload.php';

use GuzzleHttp\Client;
use DateTime;

class Listing
{
    // Connection
    private $conn;
    private $requestMethod;
    private $dbfield;
    private $status;
    // Table
    // Columns
    // public $id;
    // public $listing_key;
    // public $modification_timestamp;
    // public $standard_status;
    public function __construct($db, $requestMethod, $dbfield, $status)
    {
        $this->conn = $db;
        $this->requestMethod = $requestMethod;
        $this->dbfield = $dbfield;
        $this->status = $status;
    }

    public function processRequest()
    {
        // switch ($this->requestMethod) {
        //     case 'POST':
        //         $response = $this->getListings($this->status);
        //         break;
        //     case 'GET':
        //         if ($this->dbfield == 'seed') $response = $this->getMlsListings();
        //         else if ($this->dbfield == 'update') $response = $this->updateListings();
        //         else if ($this->dbfield == 'sold') $response = $this->countSold();
        //         else if ($this->dbfield == 'daily') $response = $this->countDay();
        //         break;
        //     default:
        //         break;
        // }
        if ($this->requestMethod == 'GET') {
            switch ($this->dbfield) {
                case 'seed':
                    $response = $this->uploadMlsListings();
                    break;
                case 'update':
                    $response = $this->updateListings();
                    break;
                case 'sold':
                    $response = $this->countSold();
                    break;
                case 'daily':
                    $response = $this->countDay();
                    break;
                case 'pending':
                    $response = $this->countPending();
                    break;
                case 'today':
                    $response = $this->getTodaysListings();
                    break;
                case 'records':
                    $response = $this->getListings($this->status);
                    break;
                case 'test':
                    $response = $this->test();
                    break;
                case 'test2':
                    $response = $this->findDupes();
                    break;
                default:
                    $response = $this->notFoundResponse();
                    break;
            }
        } else {
        }
        header($response['status_code_header']);
        if ($response['body']) {
            echo $response['body'];
        }
    }

    public function getListings()
    {
        $sqlQuery = "SELECT * FROM listings WHERE standard_status = ?";
        $stmt = $this->conn->prepare($sqlQuery);
        $stmt->bind_param('s', $this->status);
        $stmt->execute();

        $result = $stmt->get_result();
        $results = $result->fetch_all(MYSQLI_ASSOC);

        $response['status_code_header'] = 'HTTP:/1.1 200 OK';
        $response['body'] = json_encode($results);
        return $response;
    }

    public function uploadMlsListings()
    {
        $client = new Client([
            'base_uri' => 'https://api.listhub.com'
        ]);

        $token = $client->request('POST', '/public_sandbox/oauth2/token', [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => 'public_sandbox',
                'client_secret' => 'public_sandbox'
            ]
        ]);

        $key = json_decode($token->getBody(), true);
        $baseUrl = '/public_sandbox/odata/Property?$select=ListingKey,ModificationTimestamp,StandardStatus,ListingId,ListPrice';
        $queryUrl = $baseUrl;
        $finished = false;
        $i = 0;

        do {
            $listing = $client->request('GET', $queryUrl, [
                'headers' => [
                    'Authorization' => "Bearer " . $key['access_token']
                ]
            ]);

            $listings = json_decode($listing->getBody(), true);

            $sqlQuery = "INSERT INTO listings 
                SET listing_key = ?, modification_timestamp = ?, standard_status = ?, mls_id = ?, list_price = ?, created_at = NOW()";

            $stmt = $this->conn->prepare($sqlQuery);

            foreach ($listings["value"] as $mls) {
                $stmt->bind_param('sssss', $mls["ListingKey"], $mls["ModificationTimestamp"], $mls["StandardStatus"], $mls["ListingId"], $mls["ListPrice"]);
                $stmt->execute();
            }

            echo "Done one batch! \n";
            if (sizeof($listings["value"]) < 500 || !$listings["@odata.nextLink"]) { //ListHub sends a nextLink even if there's no more records, why?
                $finished = true;
            } else {
                $nextLink = $listings["@odata.nextLink"];
                echo "NEXT LINK: " . $nextLink . "\n";
                $queryUrl = $baseUrl . substr($nextLink, strpos($nextLink, '&%24skiptoken='));
                echo "URL: " . $queryUrl . "\n";
                $i++;
            }
        } while (!$finished && $i < 3);

        $response['status_code_header'] = 'HTTP:/1.1 200 OK';
        $response['body'] = json_encode(array('message' => 'Database seeded!'));
        return $response;
    }

    public function updateListings()
    {
        $client = new Client([
            'base_uri' => 'https://api.listhub.com'
        ]);

        $token = $client->request('POST', '/public_sandbox/oauth2/token', [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => 'public_sandbox',
                'client_secret' => 'public_sandbox'
            ]
        ]);

        $key = json_decode($token->getBody(), true);

        //Using yesterday's date as baseline for search
        // $yestDate = new DateTime(time()); 
        // $yestDate = new DateTime('2022-07-21T16:55:38.000-05:00');
        // $yestDate->modify("-1 day");
        // $yestDate->format("Y-m-d\TH:i:s.Z\Z");

        // $yestDate = date_create('now')->modify('-1 day')->format('Y-m-d\TH:i:s.Z\Z');
        // echo $yestDate;

        //Using newest record's date as baseline
        // $dateQuery = "SELECT modification_timestamp FROM listings ORDER BY modification_timestamp DESC LIMIT 1";
        // $stmt = $this->conn->prepare($dateQuery);
        // $stmt->execute();
        // $dataRow = $stmt->fetch(PDO::FETCH_ASSOC);
        // $newestDate = $dataRow["modification_timestamp"];
        // echo $newestDate;

        $newestDate = '2022-07-21T16:55:37.999-00:00';
        echo '/public_sandbox/odata/Property?$top=10&$select=ListingKey,ModificationTimestamp,StandardStatus&$filter=ModificationTimestamp ge ' . $newestDate . "\n";

        $newListings = $client->request('GET', '/public_sandbox/odata/Property?$select=ListingKey,ModificationTimestamp,StandardStatus&$filter=ModificationTimestamp ge ' . $newestDate, [
            'headers' => [
                'Authorization' => "Bearer " . $key['access_token']
            ],
        ]);

        $listings = json_decode($newListings->getBody(), true);
        $results = [];

        $sqlQuery = "INSERT INTO listings 
            SET listing_key = ?, modification_timestamp = ?, standard_status = ?, created_at = NOW()
            ON DUPLICATE KEY UPDATE
            listing_key = ?, modification_timestamp = ?, standard_status = ?, updated_at = NOW()";

        $stmt = $this->conn->prepare($sqlQuery);

        foreach ($listings["value"] as $mls) {
            // echo var_dump($mls);
            $stmt->bind_param('ssssss', $mls["ListingKey"], $mls["ModificationTimestamp"], $mls["StandardStatus"], $mls["ListingKey"], $mls["ModificationTimestamp"], $mls["StandardStatus"]);
            $stmt->execute();

            $results[] = $mls;
        }

        $response['status_code_header'] = 'HTTP:/1.1 200 OK';
        $response['body'] = json_encode($results);
        return $response;
    }

    public function countSold()
    {
        $sqlQuery = "SELECT COUNT(*) FROM listings WHERE standard_status = 'Closed'";
        $results = mysqli_query($this->conn, $sqlQuery);
        // echo mysqli_fetch_assoc($results)["COUNT(*)"];
        $count = mysqli_fetch_assoc($results)["COUNT(*)"];

        $response['status_code_header'] = 'HTTP:/1.1 200 OK';
        $response['body'] = json_encode($count);
        return $response;
    }

    public function countPending()
    {
        $sqlQuery = "SELECT COUNT(*) FROM listings WHERE standard_status = 'Pending'";
        $results = mysqli_query($this->conn, $sqlQuery);
        $count = mysqli_fetch_assoc($results)["COUNT(*)"];

        $response['status_code_header'] = 'HTTP:/1.1 200 OK';
        $response['body'] = json_encode($count);
        return $response;
    }

    public function countDay()
    {
        $sqlQuery = "SELECT COUNT(*), SUBSTRING(modification_timestamp, 1, 10) FROM listings GROUP BY SUBSTRING(modification_timestamp, 1, 10)";
        $query = mysqli_query($this->conn, $sqlQuery);
        $rows = mysqli_fetch_all($query);
        $results = [];

        foreach ($rows as list($hits, $time)) {
            $results[] = [
                'day' => $time,
                'count' => (int) $hits
            ];
        }

        $response['status_code_header'] = 'HTTP:/1.1 200 OK';
        $response['body'] = json_encode($results);
        return $response;
    }

    public function getTodaysListings()
    {
        $todaysDate = new DateTime('2022-07-21');
        $todayString = date_format($todaysDate, 'Y-m-d');
        echo $todayString;
        $sqlQuery = "SELECT listing_key, standard_status FROM listings WHERE SUBSTRING(modification_timestamp, 1, 10) = '$todayString'";
        $query = mysqli_query($this->conn, $sqlQuery);
        $rows = mysqli_fetch_all($query);
        $results = [];

        foreach ($rows as list($key, $status)) {
            $results[] = [
                'listingKey' => $key,
                'status' => $status
            ];
        }

        $response['status_code_header'] = 'HTTP:/1.1 200 OK';
        $response['body'] = json_encode($results);
        return $response;
    }

    private function notFoundResponse()
    {
        $response['status_code_header'] = 'HTTP/1.1 404 Not Found';
        $response['body'] = null;
        return $response;
    }

    private function test()
    {
        $client = new Client([
            'base_uri' => 'https://api.listhub.com'
        ]);

        $token = $client->request('POST', '/public_sandbox/oauth2/token', [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => 'public_sandbox',
                'client_secret' => 'public_sandbox'
            ]
        ]);

        $key = json_decode($token->getBody(), true);

        $newListings = $client->request('GET', '/public_sandbox/odata/Property?$orderby=ListingId asc', [
            'headers' => [
                'Authorization' => "Bearer " . $key['access_token']
            ],
        ]);

        $test = $newListings->getBody();
        echo $test;

        $listings = json_decode($newListings->getBody(), true);
        $response['body'] =  json_encode($newListings);
        return $response;
    }

    private function findDupes()
    {
        $sqlQuery = "SELECT listing_key FROM listings GROUP BY listing_key HAVING COUNT(listing_key) > 1";
        $query = mysqli_query($this->conn, $sqlQuery);
        $rows = mysqli_fetch_all($query);

        $ids = [];
        foreach ($rows as $id) {
            $ids[] = $id[0];
        }

        $match_string = "('" . implode("','", $ids) . "')";

        $sqlQuery2 = "SELECT * FROM listings WHERE listing_key IN $match_string ORDER BY listing_key DESC";
        $query2 = mysqli_query($this->conn, $sqlQuery2);
        $rows2 = mysqli_fetch_all($query2);
        // echo var_dump($rows);

        $response['status_code_header'] = 'HTTP:/1.1 200 OK';
        $response['body'] = json_encode($rows2);
        return $response;
    }
}
