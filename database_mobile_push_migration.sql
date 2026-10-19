USE db_dinkop;

CREATE TABLE IF NOT EXISTS mp_mobile_push_devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_type ENUM('admin', 'seller') NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    platform ENUM('android', 'ios') NOT NULL,
    device_token VARCHAR(4096) NOT NULL,
    device_token_hash BINARY(32) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mp_mobile_push_token_hash (device_token_hash),
    KEY idx_mp_mobile_push_user (user_type, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_mobile_push_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_type ENUM('admin', 'seller') NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(120) NOT NULL,
    body VARCHAR(240) NOT NULL,
    target_url VARCHAR(500) NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_at TIMESTAMP NULL DEFAULT NULL,
    sent_at TIMESTAMP NULL DEFAULT NULL,
    last_error VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mp_mobile_push_queue_pending (sent_at, attempts, last_attempt_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
