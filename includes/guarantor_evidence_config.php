<?php
require_once __DIR__ . '/private_document_storage.php';
require_once __DIR__ . '/../config/EnvConfig.php';
function configuredGuarantorEvidenceStorage(): PrivateDocumentStorage {
    $appRoot=realpath(__DIR__.'/..');
    $documentRoot=$appRoot;
    if(isset($_SERVER['DOCUMENT_ROOT'])&&$_SERVER['DOCUMENT_ROOT']!==''){
        $candidate=is_string($_SERVER['DOCUMENT_ROOT'])?realpath($_SERVER['DOCUMENT_ROOT']):false;
        if(!$candidate||!is_dir($candidate))throw new RuntimeException('The deployment document root could not be verified');
        $documentRoot=$candidate;
    }
    $root=$_ENV['PRIVATE_DOCUMENT_STORAGE_ROOT']??getenv('PRIVATE_DOCUMENT_STORAGE_ROOT')?:EnvConfig::get('PRIVATE_DOCUMENT_STORAGE_ROOT','');
    $storage=new PrivateDocumentStorage((string)$root,$documentRoot,null,$appRoot);
    $adapter=$_ENV['DOCUMENT_SCANNER_ADAPTER']??getenv('DOCUMENT_SCANNER_ADAPTER')?:EnvConfig::get('DOCUMENT_SCANNER_ADAPTER','');
    $scanner=null;
    if(is_string($adapter)&&$adapter!==''){
        $path=realpath($adapter);$privateRoot=realpath($root);
        if($path&&is_file($path)&&is_readable($path)&&!PrivateDocumentStorage::isWithin($path,$appRoot)&&!PrivateDocumentStorage::isWithin($path,$documentRoot)&&!PrivateDocumentStorage::isWithin($path,$privateRoot)){
            $level=ob_get_level();ob_start();
            try{$candidate=require $path;$output=ob_get_clean();if($output===''&&is_callable($candidate))$scanner=$candidate;}
            catch(Throwable $error){while(ob_get_level()>$level)ob_end_clean();}
        }
    }
    return new PrivateDocumentStorage((string)$root,$documentRoot,$scanner,$appRoot);
}
