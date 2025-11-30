<?php
/**
 * DiSyL Template Signer
 * 
 * Provides HMAC-based template integrity verification.
 * Prevents tampering with template files at rest.
 * 
 * @package IkabudKernel\Core\DiSyL\Security
 * @version 0.6.0
 */

namespace IkabudKernel\Core\DiSyL\Security;

class TemplateSigner
{
    /** @var string Secret key for signing */
    private static ?string $secretKey = null;
    
    /** @var string Hash algorithm */
    private static string $algorithm = 'sha256';
    
    /** @var string Signature file extension */
    private static string $signatureExtension = '.sig';
    
    /** @var bool Whether signing is enabled */
    private static bool $enabled = true;
    
    /** @var bool Whether to enforce signatures (reject unsigned templates) */
    private static bool $enforceSignatures = false;
    
    /**
     * Initialize the signer with a secret key
     * 
     * @param string|null $secretKey Secret key (will generate if not provided)
     * @param bool $enforceSignatures Whether to reject unsigned templates
     */
    public static function init(?string $secretKey = null, bool $enforceSignatures = false): void
    {
        if ($secretKey) {
            self::$secretKey = $secretKey;
        } else {
            // Try to load from config or environment
            self::$secretKey = self::loadSecretKey();
        }
        
        self::$enforceSignatures = $enforceSignatures;
    }
    
    /**
     * Load secret key from config or environment
     * 
     * @return string
     */
    private static function loadSecretKey(): string
    {
        // Try environment variable
        $envKey = getenv('DISYL_SIGNING_KEY');
        if ($envKey) {
            return $envKey;
        }
        
        // Try config file
        $configPath = dirname(__DIR__, 3) . '/config/security.php';
        if (file_exists($configPath)) {
            $config = include $configPath;
            if (isset($config['signing_key'])) {
                return $config['signing_key'];
            }
        }
        
        // Generate and store a new key
        $keyPath = dirname(__DIR__, 3) . '/storage/.signing_key';
        if (file_exists($keyPath)) {
            return file_get_contents($keyPath);
        }
        
        // Generate new key
        $newKey = bin2hex(random_bytes(32));
        
        // Try to store it
        $storageDir = dirname($keyPath);
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0700, true);
        }
        @file_put_contents($keyPath, $newKey);
        @chmod($keyPath, 0600);
        
        return $newKey;
    }
    
    /**
     * Enable or disable signing
     * 
     * @param bool $enabled
     */
    public static function setEnabled(bool $enabled): void
    {
        self::$enabled = $enabled;
    }
    
    /**
     * Set whether to enforce signatures
     * 
     * @param bool $enforce
     */
    public static function setEnforceSignatures(bool $enforce): void
    {
        self::$enforceSignatures = $enforce;
    }
    
    /**
     * Sign a template file
     * 
     * @param string $templatePath Path to template file
     * @return bool True if signed successfully
     */
    public static function sign(string $templatePath): bool
    {
        if (!self::$enabled || !self::$secretKey) {
            return false;
        }
        
        if (!file_exists($templatePath)) {
            return false;
        }
        
        $content = file_get_contents($templatePath);
        $signature = self::generateSignature($content);
        
        $signaturePath = $templatePath . self::$signatureExtension;
        $result = file_put_contents($signaturePath, $signature);
        
        if ($result !== false) {
            @chmod($signaturePath, 0644);
            return true;
        }
        
        return false;
    }
    
    /**
     * Sign template content (returns signature without saving)
     * 
     * @param string $content Template content
     * @return string Signature
     */
    public static function signContent(string $content): string
    {
        return self::generateSignature($content);
    }
    
    /**
     * Verify a template file's signature
     * 
     * @param string $templatePath Path to template file
     * @return SignatureResult
     */
    public static function verify(string $templatePath): SignatureResult
    {
        if (!self::$enabled) {
            return new SignatureResult(true, 'Signing disabled');
        }
        
        if (!file_exists($templatePath)) {
            return new SignatureResult(false, 'Template file not found');
        }
        
        $signaturePath = $templatePath . self::$signatureExtension;
        
        // Check if signature file exists
        if (!file_exists($signaturePath)) {
            if (self::$enforceSignatures) {
                return new SignatureResult(false, 'Signature file not found (enforcement enabled)');
            }
            return new SignatureResult(true, 'No signature (enforcement disabled)', false);
        }
        
        $content = file_get_contents($templatePath);
        $storedSignature = trim(file_get_contents($signaturePath));
        $expectedSignature = self::generateSignature($content);
        
        if (hash_equals($expectedSignature, $storedSignature)) {
            return new SignatureResult(true, 'Signature valid', true);
        }
        
        return new SignatureResult(false, 'Signature mismatch - template may have been tampered with');
    }
    
    /**
     * Verify template content against a signature
     * 
     * @param string $content Template content
     * @param string $signature Expected signature
     * @return bool
     */
    public static function verifyContent(string $content, string $signature): bool
    {
        $expectedSignature = self::generateSignature($content);
        return hash_equals($expectedSignature, $signature);
    }
    
    /**
     * Generate signature for content
     * 
     * @param string $content
     * @return string
     */
    private static function generateSignature(string $content): string
    {
        if (!self::$secretKey) {
            self::$secretKey = self::loadSecretKey();
        }
        
        return hash_hmac(self::$algorithm, $content, self::$secretKey);
    }
    
    /**
     * Sign all templates in a directory
     * 
     * @param string $directory Directory path
     * @param bool $recursive Include subdirectories
     * @return array Results ['signed' => count, 'failed' => count, 'files' => [...]]
     */
    public static function signDirectory(string $directory, bool $recursive = true): array
    {
        $results = [
            'signed' => 0,
            'failed' => 0,
            'files' => []
        ];
        
        if (!is_dir($directory)) {
            return $results;
        }
        
        $pattern = $recursive ? '*.disyl' : '*.disyl';
        $flags = $recursive ? \FilesystemIterator::SKIP_DOTS : 0;
        
        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, $flags)
            );
        } else {
            $iterator = new \DirectoryIterator($directory);
        }
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'disyl') {
                $path = $file->getPathname();
                if (self::sign($path)) {
                    $results['signed']++;
                    $results['files'][] = ['path' => $path, 'status' => 'signed'];
                } else {
                    $results['failed']++;
                    $results['files'][] = ['path' => $path, 'status' => 'failed'];
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Verify all templates in a directory
     * 
     * @param string $directory Directory path
     * @param bool $recursive Include subdirectories
     * @return array Results
     */
    public static function verifyDirectory(string $directory, bool $recursive = true): array
    {
        $results = [
            'valid' => 0,
            'invalid' => 0,
            'unsigned' => 0,
            'files' => []
        ];
        
        if (!is_dir($directory)) {
            return $results;
        }
        
        $flags = \FilesystemIterator::SKIP_DOTS;
        
        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, $flags)
            );
        } else {
            $iterator = new \DirectoryIterator($directory);
        }
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'disyl') {
                $path = $file->getPathname();
                $result = self::verify($path);
                
                if ($result->valid) {
                    if ($result->signed) {
                        $results['valid']++;
                        $results['files'][] = ['path' => $path, 'status' => 'valid'];
                    } else {
                        $results['unsigned']++;
                        $results['files'][] = ['path' => $path, 'status' => 'unsigned'];
                    }
                } else {
                    $results['invalid']++;
                    $results['files'][] = ['path' => $path, 'status' => 'invalid', 'reason' => $result->reason];
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Remove signature file for a template
     * 
     * @param string $templatePath
     * @return bool
     */
    public static function removeSignature(string $templatePath): bool
    {
        $signaturePath = $templatePath . self::$signatureExtension;
        if (file_exists($signaturePath)) {
            return unlink($signaturePath);
        }
        return true;
    }
}

/**
 * Signature verification result
 */
class SignatureResult
{
    public bool $valid;
    public string $reason;
    public bool $signed;
    
    public function __construct(bool $valid, string $reason = '', bool $signed = true)
    {
        $this->valid = $valid;
        $this->reason = $reason;
        $this->signed = $signed;
    }
    
    public function isValid(): bool
    {
        return $this->valid;
    }
    
    public function isSigned(): bool
    {
        return $this->signed;
    }
    
    public function getReason(): string
    {
        return $this->reason;
    }
}
