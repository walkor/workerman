<?php

var_dump(ini_get('upload_tmp_dir'));

var_dump($_REQUEST);


var_dump($_FILES);

if(isset($_FILES['file'])){
    var_dump(is_uploaded_file($_FILES['file']['tmp_name']));
    var_dump(move_uploaded_file($_FILES['file']['tmp_name'],'./'.uniqid()));
}