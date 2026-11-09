<?php

namespace SPLCloudBackup\Drive;

defined('ABSPATH') || exit;

class GoogleDriveClient
{
    private array $settings;
    private ?string $accessToken = null;
    private string $lastError = '';

    public function __construct(?array $settings = null)
    {
        $this->settings = $settings ?? get_option('spl_cloud_backup_settings', []);
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * Get or refresh Google Drive OAuth2 Access Token.
     *
     * @return string|null Access token or null on failure
     */
    public function getAccessToken(): ?string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        // Check transient cache
        $cacheKey = 'spl_drive_token_' . md5(serialize([
            $this->settings['drive_auth_type'] ?? '',
            $this->settings['service_account_json'] ?? '',
            $this->settings['oauth_refresh_token'] ?? ''
        ]));

        $cached = get_transient($cacheKey);
        if (!empty($cached)) {
            $this->accessToken = $cached;
            return $this->accessToken;
        }

        $authType = $this->settings['drive_auth_type'] ?? 'service_account';

        if ($authType === 'service_account') {
            $token = $this->getTokenViaServiceAccount();
        } else {
            $token = $this->getTokenViaOAuth();
        }

        if ($token) {
            $this->accessToken = $token;
            set_transient($cacheKey, $token, 3000); // 50 mins
            return $token;
        }

        return null;
    }

    /**
     * Authenticate via Service Account JSON key (Zero-bloat JWT generation via OpenSSL).
     */
    private function getTokenViaServiceAccount(): ?string
    {
        $jsonString = trim($this->settings['service_account_json'] ?? '');
        if (empty($jsonString)) {
            $this->lastError = 'Chưa nhập nội dung Service Account JSON.';
            return null;
        }

        $data = json_decode($jsonString, true);
        if (!is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
            $this->lastError = 'Nội dung Service Account JSON không hợp lệ (thiếu client_email hoặc private_key).';
            return null;
        }

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $payload = [
            'iss'   => $data['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/drive',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ];

        $encodedHeader = self::base64UrlEncode((string) json_encode($header));
        $encodedPayload = self::base64UrlEncode((string) json_encode($payload));
        $signingInput = $encodedHeader . '.' . $encodedPayload;

        $signature = '';
        $privateKey = $data['private_key'];

        $keyResource = openssl_pkey_get_private($privateKey);
        if (!$keyResource) {
            $this->lastError = 'Không thể đọc khóa RSA private_key từ Service Account JSON.';
            return null;
        }

        $success = openssl_sign($signingInput, $signature, $keyResource, OPENSSL_ALGO_SHA256);
        if (!$success) {
            $this->lastError = 'Lỗi ký số JWT bằng OpenSSL.';
            return null;
        }

        $jwt = $signingInput . '.' . self::base64UrlEncode($signature);

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 20,
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ]);

        if (is_wp_error($response)) {
            $this->lastError = 'Lỗi kết nối OAuth2: ' . $response->get_error_message();
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            $this->lastError = 'Google OAuth2 (Service Account): ' . ($body['error_description'] ?? ($body['error'] ?? 'Không nhận được access_token'));
            return null;
        }

        return $body['access_token'];
    }

    /**
     * Authenticate via OAuth 2.0 Refresh Token.
     */
    private function getTokenViaOAuth(): ?string
    {
        $clientId = trim($this->settings['oauth_client_id'] ?? '');
        $clientSecret = trim($this->settings['oauth_client_secret'] ?? '');
        $refreshToken = trim($this->settings['oauth_refresh_token'] ?? '');

        if (empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
            $missing = [];
            if (empty($clientId)) {
                $missing[] = 'Client ID';
            }
            if (empty($clientSecret)) {
                $missing[] = 'Client Secret';
            }
            if (empty($refreshToken)) {
                $missing[] = 'Refresh Token (chưa bấm Ủy quyền kết nối Google Drive)';
            }
            $this->lastError = 'Thiếu cấu hình OAuth 2.0: ' . implode(', ', $missing);
            return null;
        }

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 20,
            'body'    => [
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token',
            ],
        ]);

        if (is_wp_error($response)) {
            $this->lastError = 'Lỗi kết nối OAuth2: ' . $response->get_error_message();
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            $this->lastError = 'Google OAuth2: ' . ($body['error_description'] ?? ($body['error'] ?? 'Refresh token đã hết hạn hoặc bị thu hồi'));
            return null;
        }

        return $body['access_token'];
    }

    /**
     * Test connection to Google Drive API.
     *
     * @return array<string, mixed>
     */
    public function testConnection(): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return [
                'success' => false,
                'message' => __('Không thể xác thực với Google API: ', 'spl-cloud-backup') . ($this->lastError ?: __('Vui lòng kiểm tra lại cấu hình Service Account hoặc OAuth.', 'spl-cloud-backup')),
            ];
        }

        $response = wp_remote_get('https://www.googleapis.com/drive/v3/about?fields=user,storageQuota', [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            return [
                'success' => false,
                'message' => $body['error']['message'] ?? __('Lỗi phản hồi từ Google Drive API.', 'spl-cloud-backup'),
            ];
        }

        $user = $body['user']['displayName'] ?? ($body['user']['emailAddress'] ?? 'Google Account');
        $quota = $body['storageQuota'] ?? [];
        $usage = isset($quota['usage']) ? size_format((int) $quota['usage'], 2) : 'N/A';
        $limit = isset($quota['limit']) ? size_format((int) $quota['limit'], 2) : 'Unlimited';

        if (($this->settings['drive_auth_type'] ?? 'service_account') === 'service_account' && isset($quota['limit']) && (int) $quota['limit'] === 0) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Kết nối API thành công (%s), NHƯNG tài khoản Service Account này có hạn mức lưu trữ 0 Byte (0 B / 0 B) nên Google chặn tải tệp lên Drive cá nhân. Vui lòng chuyển sang phương thức "OAuth 2.0 (Client ID / Refresh Token)" để sử dụng dung lượng Google Drive của bạn!', 'spl-cloud-backup'),
                    $user
                ),
                'user'    => $user,
                'quota'   => sprintf('%s / %s', $usage, $limit),
            ];
        }

        return [
            'success' => true,
            'message' => sprintf(__('Kết nối Google Drive thành công! Tài khoản: %s (Đã dùng: %s / %s)', 'spl-cloud-backup'), $user, $usage, $limit),
            'user'    => $user,
            'quota'   => sprintf('%s / %s', $usage, $limit),
        ];
    }

    /**
     * Find or create the target backup folder on Google Drive.
     */
    public function getOrCreateFolder(string $folderName): ?string
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return null;
        }

        // Search for existing active folder (including Shared Drives and shared folders)
        $query = sprintf("name = '%s' and mimeType = 'application/vnd.google-apps.folder' and trashed = false", addslashes($folderName));
        $searchUrl = 'https://www.googleapis.com/drive/v3/files?q=' . rawurlencode($query)
                   . '&fields=files(id,name)&supportsAllDrives=true&includeItemsFromAllDrives=true';

        $response = wp_remote_get($searchUrl, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($data['files'][0]['id'])) {
                return $data['files'][0]['id'];
            }
        } elseif (is_wp_error($response)) {
            $this->lastError = $response->get_error_message();
        } else {
            $errData = json_decode(wp_remote_retrieve_body($response), true);
            $this->lastError = $errData['error']['message'] ?? ('HTTP ' . wp_remote_retrieve_response_code($response));
        }

        // Folder not found, create new folder
        $createResponse = wp_remote_post('https://www.googleapis.com/drive/v3/files?supportsAllDrives=true', [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'name'     => $folderName,
                'mimeType' => 'application/vnd.google-apps.folder',
            ]),
        ]);

        if (!is_wp_error($createResponse) && wp_remote_retrieve_response_code($createResponse) === 200) {
            $data = json_decode(wp_remote_retrieve_body($createResponse), true);
            return $data['id'] ?? null;
        }

        if (is_wp_error($createResponse)) {
            $this->lastError = $createResponse->get_error_message();
        } else {
            $errData = json_decode(wp_remote_retrieve_body($createResponse), true);
            $this->lastError = $errData['error']['message'] ?? ('HTTP ' . wp_remote_retrieve_response_code($createResponse));
        }

        return null;
    }

    /**
     * Upload a local file to Google Drive using Google Resumable Upload protocol.
     * Handles large files (50MB - 1GB+) without timeouts.
     *
     * @param string $localFilePath Absolute path to local file
     * @param string $remoteFileName Name to save as on Drive
     * @param string|null $folderId Target Drive folder ID
     * @param callable|null $onProgress Callback function(string $message, float $percent)
     * @return array<string, mixed>
     */
    public function uploadFile(string $localFilePath, string $remoteFileName, ?string $folderId = null, ?callable $onProgress = null): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return [
                'success' => false,
                'message' => __('Không thể xác thực tài khoản Google Drive.', 'spl-cloud-backup'),
            ];
        }

        if (!file_exists($localFilePath) || !is_readable($localFilePath)) {
            return [
                'success' => false,
                'message' => __('Tệp tin sao lưu cục bộ không tồn tại hoặc không thể đọc.', 'spl-cloud-backup'),
            ];
        }

        $fileSize = (int) filesize($localFilePath);
        $mimeType = str_ends_with($remoteFileName, '.gz') ? 'application/gzip' : 'application/zip';

        if ($onProgress) {
            $onProgress(sprintf(__('Khởi tạo phiên tải lên Google Drive (%s)...', 'spl-cloud-backup'), size_format($fileSize, 2)), 5);
        }

        // Step 1: Initiate Resumable Upload Session
        $metadata = ['name' => $remoteFileName];
        if ($folderId) {
            $metadata['parents'] = [$folderId];
        }

        $initUrl = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true&fields=id,name,webViewLink,webContentLink,size';
        $initResponse = wp_remote_post($initUrl, [
            'timeout' => 30,
            'headers' => [
                'Authorization'          => 'Bearer ' . $token,
                'Content-Type'           => 'application/json; charset=UTF-8',
                'X-Upload-Content-Type'   => $mimeType,
                'X-Upload-Content-Length' => (string) $fileSize,
            ],
            'body' => json_encode($metadata),
        ]);

        if (is_wp_error($initResponse)) {
            return [
                'success' => false,
                'message' => sprintf(__('Lỗi khởi tạo upload: %s', 'spl-cloud-backup'), $initResponse->get_error_message()),
            ];
        }

        $initCode = wp_remote_retrieve_response_code($initResponse);
        if ($initCode !== 200) {
            $errBody = json_decode(wp_remote_retrieve_body($initResponse), true);
            return [
                'success' => false,
                'message' => $errBody['error']['message'] ?? __('Không thể khởi tạo phiên tải lên Drive.', 'spl-cloud-backup'),
            ];
        }

        $sessionUri = wp_remote_retrieve_header($initResponse, 'location');
        if (empty($sessionUri)) {
            return [
                'success' => false,
                'message' => __('Google Drive không trả về session URI cho phiên tải lên.', 'spl-cloud-backup'),
            ];
        }

        // Step 2: Stream file in 5MB chunks (multiple of 256KB)
        $chunkSize = 5 * 1024 * 1024; // 5MB
        $handle = fopen($localFilePath, 'rb');
        if (!$handle) {
            return [
                'success' => false,
                'message' => __('Không thể mở tệp tin để đọc dữ liệu upload.', 'spl-cloud-backup'),
            ];
        }

        $uploadedBytes = 0;
        $fileData = null;

        while ($uploadedBytes < $fileSize) {
            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }

            $currentChunkLength = min($chunkSize, $fileSize - $uploadedBytes);
            $chunkData = fread($handle, $currentChunkLength);

            if ($chunkData === false) {
                fclose($handle);
                return [
                    'success' => false,
                    'message' => __('Lỗi đọc dữ liệu tệp tin khi đang upload.', 'spl-cloud-backup'),
                ];
            }

            $start = $uploadedBytes;
            $end = $start + strlen($chunkData) - 1;

            $chunkResponse = wp_remote_request($sessionUri, [
                'method'  => 'PUT',
                'timeout' => 60,
                'headers' => [
                    'Content-Length' => (string) strlen($chunkData),
                    'Content-Range'  => sprintf('bytes %d-%d/%d', $start, $end, $fileSize),
                ],
                'body' => $chunkData,
            ]);

            unset($chunkData);

            if (is_wp_error($chunkResponse)) {
                fclose($handle);
                return [
                    'success' => false,
                    'message' => sprintf(__('Lỗi upload chunk: %s', 'spl-cloud-backup'), $chunkResponse->get_error_message()),
                ];
            }

            $code = wp_remote_retrieve_response_code($chunkResponse);

            if ($code === 308) {
                // Resume Incomplete - advance to next chunk
                $uploadedBytes += $currentChunkLength;
                $pct = round(($uploadedBytes / $fileSize) * 90, 1);
                if ($onProgress) {
                    $onProgress(sprintf(__('Đang tải lên Drive: %s / %s (%s%%)', 'spl-cloud-backup'), size_format($uploadedBytes, 2), size_format($fileSize, 2), $pct), $pct);
                }
            } elseif ($code === 200 || $code === 201) {
                // Completed!
                $uploadedBytes += $currentChunkLength;
                $fileData = json_decode(wp_remote_retrieve_body($chunkResponse), true);
                break;
            } else {
                fclose($handle);
                $err = json_decode(wp_remote_retrieve_body($chunkResponse), true);
                return [
                    'success' => false,
                    'message' => $err['error']['message'] ?? sprintf(__('Upload thất bại với mã HTTP %d', 'spl-cloud-backup'), $code),
                ];
            }
        }

        fclose($handle);

        if ($fileData && !empty($fileData['id'])) {
            if ($onProgress) {
                $onProgress(__('Tải lên Google Drive hoàn tất thành công!', 'spl-cloud-backup'), 100);
            }

            return [
                'success'      => true,
                'file_id'      => $fileData['id'],
                'file_name'    => $fileData['name'] ?? $remoteFileName,
                'web_link'     => $fileData['webViewLink'] ?? "https://drive.google.com/file/d/{$fileData['id']}/view",
                'size'         => $fileSize,
                'message'      => sprintf(__('Đã tải lên Google Drive thành công: %s', 'spl-cloud-backup'), $remoteFileName),
            ];
        }

        return [
            'success' => false,
            'message' => __('Upload hoàn tất nhưng không nhận được file ID từ Google Drive.', 'spl-cloud-backup'),
        ];
    }

    /**
     * List files inside a Google Drive folder.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFilesInFolder(string $folderId, int $max = 50): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return [];
        }

        $query = sprintf("'%s' in parents and trashed = false", addslashes($folderId));
        $url = 'https://www.googleapis.com/drive/v3/files?q=' . rawurlencode($query)
             . '&orderBy=createdTime desc&pageSize=' . $max
             . '&supportsAllDrives=true&includeItemsFromAllDrives=true'
             . '&fields=files(id,name,size,createdTime,webViewLink)';

        $response = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['files'] ?? [];
    }

    /**
     * Delete a file on Google Drive by ID.
     */
    public function deleteFile(string $fileId): bool
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return false;
        }

        $response = wp_remote_request('https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?supportsAllDrives=true', [
            'method'  => 'DELETE',
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        return !is_wp_error($response) && in_array(wp_remote_retrieve_response_code($response), [200, 204], true);
    }

    /**
     * Base64 URL encode helper.
     */
    public static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
}
