<?php

// ini_set("display_errors", 1);
// error_reporting(E_ALL);

// $Opt["dbHost"] = "db_mysql";
// $Opt["dsn"] = "mysql://hotcrp:idrspclye6eudr@db_mysql:3306/hotcrpprod24";
// $Opt["dbUser"] = "hotcrp";
// $Opt["dbPassword"] = "idrspclye6eudr";
// $Opt["db"] = "hotcrpprod24";

// // make mysql connection
// $db = mysqli_connect($Opt["dbHost"], $Opt["dbUser"], $Opt["dbPassword"], $Opt["db"]);
// if (!$db) {
//     die("Connection failed: " . mysqli_connect_error());
// } else {
//     echo "DB OK";
// }

// // get data from Settings table and print as table
// $sql = "SELECT * FROM Settings";
// $result = mysqli_query($db, $sql);
// if (mysqli_num_rows($result) > 0) {
//     echo "<table><tr><th>Name</th><th>Value</th><th>Data<7th></tr>";
//     while($row = mysqli_fetch_assoc($result)) {
//         echo "<tr><td>" . $row["name"]. "</td><td>" . $row["value"]. "</td><td><pre>" . $row["data"]. "</pre></td></tr>";
//     }
// } else {
//     echo "0 results";
// }