<?php
require dirname(__DIR__,2).'/app/bootstrap.php';$u=require_user();require_method('POST');require_csrf();$file=$_FILES['photo']??null;
if(!$file||$file['error']!==UPLOAD_ERR_OK||$file['size']>3*1024*1024)json_out(['error'=>'Choose a JPG, PNG or WebP image up to 3 MB.'],422);
$info=@getimagesize($file['tmp_name']);if(!$info||!in_array($info['mime'],['image/jpeg','image/png','image/webp'],true)||$info[0]>4096||$info[1]>4096)json_out(['error'=>'Use a valid image no larger than 4096 × 4096.'],422);
$image=@imagecreatefromstring(file_get_contents($file['tmp_name']));if(!$image)json_out(['error'=>'Image could not be read.'],422);
$scale=min(1,512/max($info[0],$info[1]));$w=max(1,(int)round($info[0]*$scale));$h=max(1,(int)round($info[1]*$scale));$canvas=imagecreatetruecolor($w,$h);imagefill($canvas,0,0,imagecolorallocate($canvas,20,20,32));imagecopyresampled($canvas,$image,0,0,0,0,$w,$h,$info[0],$info[1]);
$dir=dirname(__DIR__).'/uploads/profiles';if(!is_dir($dir))mkdir($dir,0755,true);$name=bin2hex(random_bytes(20)).'.jpg';if(!imagejpeg($canvas,$dir.'/'.$name,88))throw new RuntimeException('Photo could not be saved');imagedestroy($canvas);imagedestroy($image);
$url='/uploads/profiles/'.$name;db()->prepare('INSERT INTO customer_profiles(user_id,avatar_url) VALUES(?,?) ON DUPLICATE KEY UPDATE avatar_url=VALUES(avatar_url)')->execute([$u['id'],$url]);audit('profile.photo_updated',(int)$u['id']);json_out(['ok'=>true,'avatar_url'=>$url]);
