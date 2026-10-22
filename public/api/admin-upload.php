<?php
require_once dirname(__DIR__,2).'/app/bootstrap.php';
require_admin();require_method('POST');require_csrf();
$file=$_FILES['image']??null;if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)json_out(['error'=>'Choose an image to upload.'],422);
if((int)$file['size']<1||(int)$file['size']>5*1024*1024||!is_uploaded_file($file['tmp_name']))json_out(['error'=>'Image uploads must be 5 MB or smaller.'],422);
$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file($file['tmp_name']);$extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/vnd.microsoft.icon'=>'ico','image/x-icon'=>'ico'];if(!isset($extensions[$mime]))json_out(['error'=>'Use a JPEG, PNG, WebP, GIF, or ICO image. SVG and executable files are not accepted.'],422);
$dir=dirname(__DIR__).'/uploads/content';if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))json_out(['error'=>'Image storage is unavailable.'],500);
$name=bin2hex(random_bytes(18)).'.'.$extensions[$mime];if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$name))json_out(['error'=>'Unable to save this image.'],500);
json_out(['ok'=>true,'url'=>'/uploads/content/'.$name]);
