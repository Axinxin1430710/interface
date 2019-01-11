
<?php
/**
 * 基于tp5.1的阿里云 oss 的接口封装请求
 * Created by PhpStorm.
 * User: panzibin
 * Date: 2018-12-27
 * Time: 10:21
 */

namespace app\common\event;

use app\common\exception\MyException;
use OSS\Core\OssException;
use OSS\Core\OssUtil;
use OSS\OssClient;

class AliOss
{
    private $oss;
    private $accessKeyId;
    private $accessKeySecret;
    private $bucket;
    private $endPoint;
    private $position=0;//追加上传位置
    private $partSize=10 * 1024 * 1024;
    private $isCheckMd5=true;
    private $uploadPosition= 0;
    private $uploadParts= array();
    private $object;
    private $uploadId;
    private $partUploadFile;
    private $simpleCopyFileSize = 1024 * 1024 * 1024;
    public function __construct()
    {
        $this->bucket = "阿里云Oss的存储空间";
        $this->accessKeyId = "阿里云OSs的accessKeyId";
        $this->accessKeySecret = "阿里云OSs的accessKeySecret";
        $this->endPoint = "阿里云OSs的endPoint";
        $this->init();
    }
    //初始化
    private function init(){
        try{
            $this->oss = new OssClient($this->accessKeyId,$this->accessKeySecret,$this->endPoint);
            if(!$this->oss->doesBucketExist($this->bucket)){
                $options =array(OssClient::OSS_STORAGE => OssClient::OSS_STORAGE_IA);
                $this->oss->createBucket($this->bucket,OssClient::OSS_ACL_TYPE_PUBLIC_READ, $options);
            }
        }catch (OssException $e){
            trace($e->getMessage(),'log');
            throw new MyException('阿里Oss初始化失败');
        }
    }

    /**
     * 简单上传：主要用于上传字符串
     * @param $object
     * @param $content
     * @throws MyException
     */
    public function uploadString($object,$content){
        try{
            $this->oss->putObject($this->bucket,$object,$content);
            return 'ok';
        }catch (OssException $e){
            trace($e->getMessage(),'error');
            throw new MyException($e->getErrorMessage());
        }
    }

    /**
     * 上传文件
     * @param $object 存在在ali Oss的对象
     * @param $file 所要存储的文件
     * @throws MyException
     */
    public function uploadFile($object,$file){
        try{
            $this->oss->uploadFile($this->bucket,$object,$file);
            return 'ok';
        }catch (OssException $e){
            trace($e->getMessage(),'error');
            throw new MyException($e->getErrorMessage());
        }
    }

    /**
     * 追加上传文件
     * @param $object 存在在ali Oss的对象
     * @param $files 所要存储的文件集
     * @throws MyException
     */
    public function appendUpload($object,$files=array()){
        try{
            foreach ($files as $file){
                $this->position = $this->oss->appendFile($this->bucket,$object,$file,$this->position);
            }
            return 'ok';
        }catch (OssException $e){
            trace($e->getMessage(),'error');
            throw new MyException($e->getErrorMessage());
        }

    }

    /**
     * 分片上传文件
     * @param $object 存在在ali Oss的对象
     * @param $file 所要存储的文件
     * @throws MyException
     */
    public function partUpload($object,$file){
        try{
            $this->object = $object;
            $this->partUploadFile = $file;
            $this->initPartUpload();
            $this->uploadPart();
            $this->finishUploadPart();
            return 'ok';
        }catch (OssException $e){
            trace($e->getMessage(),'error');
            throw new MyException($e->getErrorMessage());
        }
    }

    //初始化分片上传事件，获得uploadId
    private function initPartUpload(){
        try{
            $this->uploadId = $this->oss->initiateMultipartUpload($this->bucket, $this->object);
        }catch (OssException $e){
            trace($e->getMessage(),'error');
            throw new MyException($e->getErrorMessage());
        }

    }

    //上传分片
    private function uploadPart(){
        try{
            $fileSize = filesize($this->partUploadFile);
            $pieces = $this->oss->generateMultiuploadParts($fileSize,$this->partSize);
            $responseUploadPart = array();
            foreach ($pieces as $k=>$value){
                $fromPos = $this->uploadPosition + (integer)$value[($this->oss)::OSS_SEEK_TO];
                $toPos = (integer)$value[($this->oss)::OSS_LENGTH] + $fromPos -1;
                $upOptions = array(
                    ($this->oss)::OSS_FILE_UPLOAD => $this->partUploadFile,
                    ($this->oss)::OSS_PART_NUM => ($k + 1),
                    ($this->oss)::OSS_SEEK_TO => $fromPos,
                    ($this->oss)::OSS_LENGTH => $toPos - $fromPos + 1,
                    ($this->oss)::OSS_CHECK_MD5 => $this->isCheckMd5
                );
                if($this->isCheckMd5){
                    $contentMd5 = OssUtil::getMd5SumForFile($this->partUploadFile, $fromPos, $toPos);
                    $upOptions[($this->oss)::OSS_CONTENT_MD5] = $contentMd5;
                }
                $responseUploadPart[] = $this->oss->uploadPart($this->bucket, $this->object, $this->uploadId, $upOptions);
            }
            foreach ($responseUploadPart as $k=>$value){
                array_push($this->uploadParts,array(
                    'PartNumber'=>($k+1),
                    'ETag'=>$value));
            }
        }catch (OssException $e){
            trace($e->getMessage(),'error');
            throw new MyException($e->getErrorMessage());
        }
    }

    //完成分片上传
    private function finishUploadPart(){
       try{
           $this->oss->completeMultipartUpload($this->bucket,$this->object,$this->uploadId,$this->uploadParts);
       }catch (OssException $e){
           trace($e->getMessage(),'error');
           throw new MyException($e->getErrorMessage());
       }
    }

    /**
     * 下载文件
     * @param $object
     * @param null $localFile 指定文件下载路径 ,如果为空，则下载文件到内存
     * @param null $range 范围下载，获取多少个字节，格式：'0-4',若为空，则
     * @return mixed
     * @throws MyException
     */
    public function downloadFile($object,$localFile = null,$range = null){
        try{
            if(!is_null($localFile)||!is_null($range)){
                $options = array();
                if($localFile):$options[OssClient::OSS_FILE_DOWNLOAD] = $localFile;endif;

                if($range):$options[OssClient::OSS_RANGE] = $range;endif;

                $content = $this->oss->getObject($this->bucket,$object,$options);
            }else{
                $content = $this->oss->getObject($this->bucket,$object);
            }
            return $content;
        }catch (OssException $e){
            trace($e->getMessage(),'error');
            throw new MyException($e->getErrorMessage());
        }
    }


    /**
     * 判断指定的文件时候存在
     * @return mixed
     */
    private function doesObjectExist(){
        return $this->oss->doesObjectExist($this->bucket,$this->object);
    }

    /**
     * 设置文件权限
     * @param $acl
     * @throws MyException
     */
    public function setAcl($acl){
        try{
            $this->oss->putObjectAcl($this->bucket,$this->object,$acl);
            return 'ok';
        }catch (OssException $e){
            trace($e->getMessage(),'error');
            throw new MyException($e->getErrorMessage());
        }
    }

    /**
     * 获取文件的权限
     * @return mixed
     */
    private function getAcl(){
        return $this->oss->getObjectAcl($this->bucket,$this->object);
    }

    /**
     * 删除文件
     * @param array $objects
     * @throws MyException
     */
    public function deleteObjects($objects=array()){
        try{
            $this->oss->deleteObjects($this->bucket,$objects);
            return 'ok';
        }catch (OssException $e){
            trace($e->getMessage(),'error');
            throw new MyException($e->getErrorMessage());
        }
    }

    public function copyObject($object,$dstBucket,$dstObject){
        try{
            $this->object = $object;
            $fileSize = $this->getObjectMeta()['content-length'];
            if($fileSize < $this->simpleCopyFileSize){
                $this->oss->copyObject($this->bucket,$object,$dstBucket,$dstObject);
            }else{
                $uploadId = $this->oss->initiateMultipartUpload($dstBucket,$dstObject);
                $copyId = 1;
                $eTag = $this->oss->uploadPartCopy($this->bucket,$object,$dstBucket,$dstObject,$copyId,$uploadId);
                $uploadParts[] = array(
                    'PartNumber' => $copyId,
                    'ETag' => $eTag,
                );
                $this->oss->completeMultipartUpload($dstBucket,$dstObject,$uploadId,$uploadParts);
            }
            return 'ok';
        }catch (OssException $e){
            trace($e->getMessage(),'error');
            throw new MyException($e->getErrorMessage());
        }
    }
    private function getObjectMeta(){
        try{
            return $this->oss->getObjectMeta($this->bucket,$this->object);
        }catch (OssException $e){
            trace($e->getMessage(),'error');
            throw new MyException($e->getErrorMessage());
        }
    }
}
