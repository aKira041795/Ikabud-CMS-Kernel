<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;
use Throwable;

/** Private, tenant-scoped conversation attachment storage. */
final class HarppAttachmentService
{
    public const MAX_BYTES = 10485760; // 10 MiB

    /** Extension => MIME types accepted after inspecting the uploaded bytes. */
    private const ALLOWED = [
        'txt' => ['text/plain'],
        'md' => ['text/plain'],
        'csv' => ['text/plain', 'text/csv', 'application/csv'],
        'json' => ['application/json', 'text/plain'],
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'doc' => ['application/msword', 'application/x-ole-storage'],
        'docx' => ['application/zip', 'application/x-zip-compressed', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage'],
        'xlsx' => ['application/zip', 'application/x-zip-compressed', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    public function __construct(private ModuleDB $database, private ?string $storagePath = null) {}

    /**
     * Store one PHP upload. The HTTP handler always leaves $requireHttpUpload true;
     * false exists solely so CLI tests can exercise byte validation without forging SAPI state.
     */
    public function upload(array $actor, int $conversationId, array $file, ?int $messageId = null, ?int $tenantId = null, bool $requireHttpUpload = true): HarppServiceResult
    {
        if (!$this->actorAllowed($actor, $tenantId) || ($actor['source'] ?? 'harpp') !== 'harpp') {
            return HarppServiceResult::failure('Authentication is required.', 401, 'attachment_unauthorized');
        }
        if ($conversationId <= 0 || !$this->conversationOwnedBy($conversationId, (int)$actor['id'])) {
            return HarppServiceResult::failure('Conversation not found.', 404, 'attachment_conversation_not_found');
        }
        if ($messageId !== null && !$this->messageBelongsTo($messageId, $conversationId)) {
            return HarppServiceResult::failure('Message not found in this conversation.', 404, 'attachment_message_not_found');
        }
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp) || ($requireHttpUpload && !is_uploaded_file($tmp))) {
            return HarppServiceResult::failure('A non-empty file upload is required.', 422, 'attachment_missing');
        }
        $size = filesize($tmp);
        if ($size === false || $size <= 0) {
            return HarppServiceResult::failure('Empty files are not allowed.', 422, 'attachment_empty');
        }
        // Use the measured temporary-file size, never the client supplied size.
        if ($size > self::MAX_BYTES) {
            return HarppServiceResult::failure('File exceeds the 10 MB attachment limit.', 413, 'attachment_too_large');
        }

        $clientName = $this->clientFilename((string)($file['name'] ?? ''));
        $decodedName = rawurldecode($clientName);
        $extension = strtolower(pathinfo($decodedName, PATHINFO_EXTENSION));
        if ($extension === '' || !array_key_exists($extension, self::ALLOWED)) {
            return HarppServiceResult::failure('This file type is not allowed.', 422, 'attachment_type_denied');
        }
        $detected = $this->detectMime($tmp);
        if ($detected === '' || !in_array($detected, self::ALLOWED[$extension], true)) {
            return HarppServiceResult::failure('File content does not match an allowed type.', 422, 'attachment_mime_denied');
        }

        $tenant = (int)$tenantId;
        $relativeDir = 'uploads/harpp/' . $tenant . '/' . $conversationId;
        $relativePath = $relativeDir . '/' . bin2hex(random_bytes(24));
        $directory = $this->storageRoot() . '/' . $relativeDir;
        $destination = $this->storageRoot() . '/' . $relativePath;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            return HarppServiceResult::failure('Attachment storage is unavailable.', 500, 'attachment_storage_failed');
        }
        if (!move_uploaded_file($tmp, $destination) && !(!$requireHttpUpload && rename($tmp, $destination))) {
            return HarppServiceResult::failure('Attachment could not be stored.', 500, 'attachment_storage_failed');
        }
        @chmod($destination, 0640);

        try {
            $sha256 = hash_file('sha256', $destination);
            if (!is_string($sha256)) throw new \RuntimeException('Unable to hash stored attachment.');
            $stmt = $this->database->prepare(
                'INSERT INTO harpp_attachments (tenant_id,conversation_id,message_id,uploaded_by,client_filename,storage_path,claimed_mime,detected_mime,file_size,sha256,created_at) '
                . 'VALUES (:tenant,:conversation,:message,:uploader,:filename,:path,:claimed,:detected,:size,:sha,NOW(6))'
            );
            $stmt->execute([
                ':tenant'=>$tenant, ':conversation'=>$conversationId, ':message'=>$messageId,
                ':uploader'=>(int)$actor['id'], ':filename'=>$clientName, ':path'=>$relativePath,
                ':claimed'=>substr(trim((string)($file['type'] ?? '')), 0, 191) ?: null,
                ':detected'=>$detected, ':size'=>$size, ':sha'=>$sha256,
            ]);
            $id = (int)$this->database->lastInsertId();
            return HarppServiceResult::success(['attachment'=>$this->metadata($id, $clientName, $detected, (int)$size, $sha256, $conversationId, $messageId)]);
        } catch (Throwable $e) {
            @unlink($destination);
            $this->log('attachment metadata insert failed', $e);
            return HarppServiceResult::failure('Attachment could not be recorded.', 500, 'attachment_database_failed');
        }
    }

    public function list(array $actor, int $conversationId, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->actorAllowed($actor, $tenantId) || $conversationId <= 0) return HarppServiceResult::failure('Forbidden.', 403);
        $bridge = ($actor['source'] ?? '') === 'harpp_bridge';
        if (!$bridge && !$this->conversationOwnedBy($conversationId, (int)$actor['id'])) return HarppServiceResult::failure('Conversation not found.', 404);
        if ($bridge && !$this->conversationExists($conversationId)) return HarppServiceResult::failure('Conversation not found.', 404);
        $sql = 'SELECT id,conversation_id,message_id,client_filename,claimed_mime,detected_mime,file_size,sha256,created_at FROM harpp_attachments WHERE tenant_id=:tenant AND conversation_id=:conversation';
        $params = [':tenant'=>(int)$tenantId, ':conversation'=>$conversationId];
        if (!$bridge) { $sql .= ' AND uploaded_by=:user'; $params[':user']=(int)$actor['id']; }
        $sql .= ' ORDER BY id ASC';
        $stmt=$this->database->prepare($sql);$stmt->execute($params);
        return HarppServiceResult::success(['attachments'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function download(array $actor, int $attachmentId, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->actorAllowed($actor, $tenantId) || $attachmentId <= 0) return HarppServiceResult::failure('Forbidden.', 403);
        $bridge = ($actor['source'] ?? '') === 'harpp_bridge';
        $sql='SELECT a.*,c.id AS scoped_conversation FROM harpp_attachments a JOIN harpp_conversations c ON c.id=a.conversation_id WHERE a.id=:id AND a.tenant_id=:tenant';
        $params=[':id'=>$attachmentId,':tenant'=>(int)$tenantId];
        if (!$bridge) { $sql.=' AND a.uploaded_by=:uploader AND c.created_by=:creator'; $params[':uploader']=(int)$actor['id'];$params[':creator']=(int)$actor['id']; }
        $stmt=$this->database->prepare($sql);$stmt->execute($params);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return HarppServiceResult::failure('Attachment not found.', 404, 'attachment_not_found');
        $root=realpath($this->storageRoot());$path=realpath($this->storageRoot().'/'.(string)$row['storage_path']);
        if ($root===false || $path===false || !str_starts_with($path, $root.DIRECTORY_SEPARATOR) || !is_file($path)) {
            return HarppServiceResult::failure('Attachment file is unavailable.', 404, 'attachment_file_missing');
        }
        return HarppServiceResult::success(['path'=>$path,'filename'=>(string)$row['client_filename'],'mime'=>(string)$row['detected_mime'],'size'=>(int)$row['file_size'],'sha256'=>(string)$row['sha256']]);
    }

    private function actorAllowed(array $actor, ?int $tenantId): bool
    {
        $current=(int)(\app()->tenant()->current()??0);
        return $current>0 && $tenantId===$current && (int)($actor['id']??0)>0
            && in_array((string)($actor['source']??'harpp'), ['harpp','harpp_bridge'], true)
            && in_array((string)($actor['role']??''), ['owner','admin','member'], true);
    }
    private function conversationOwnedBy(int $id,int $user):bool{$s=$this->database->prepare('SELECT 1 FROM harpp_conversations WHERE id=:id AND created_by=:user AND deleted_at IS NULL');$s->execute([':id'=>$id,':user'=>$user]);return$s->fetchColumn()!==false;}
    private function conversationExists(int $id):bool{$s=$this->database->prepare('SELECT 1 FROM harpp_conversations WHERE id=:id AND deleted_at IS NULL');$s->execute([':id'=>$id]);return$s->fetchColumn()!==false;}
    private function messageBelongsTo(int$id,int$conversation):bool{$s=$this->database->prepare('SELECT 1 FROM harpp_messages WHERE id=:id AND conversation_id=:conversation');$s->execute([':id'=>$id,':conversation'=>$conversation]);return$s->fetchColumn()!==false;}
    private function detectMime(string $path):string{$f=new \finfo(FILEINFO_MIME_TYPE);$m=$f->file($path);return is_string($m)?strtolower(trim($m)):'';}
    private function clientFilename(string$name):string{$name=str_replace(["\0","\r","\n"],'',trim($name));if($name==='')$name='attachment';return substr($name,0,255);}
    private function storageRoot():string{$default=defined('STORAGE_PATH')?(string)STORAGE_PATH:(defined('IK_ROOT')?(string)IK_ROOT:dirname(__DIR__,3)).'/storage';return rtrim($this->storagePath??$default,'/');}
    private function metadata(int$id,string$name,string$mime,int$size,string$sha,int$conversation,?int$message):array{return['id'=>$id,'conversation_id'=>$conversation,'message_id'=>$message,'client_filename'=>$name,'detected_mime'=>$mime,'file_size'=>$size,'sha256'=>$sha];}
    private function log(string$message,Throwable$e):void{if(function_exists('write_log'))\write_log('HARPP '.$message,'error',['module'=>'harpp','error'=>$e->getMessage()]);}
}
