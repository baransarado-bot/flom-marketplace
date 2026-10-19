CREATE TABLE IF NOT EXISTS mp_shops (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(60) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    business_name VARCHAR(160) NOT NULL,
    logo_path VARCHAR(255) DEFAULT NULL,
    owner_name VARCHAR(120) NOT NULL,
    category VARCHAR(80) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    facebook_url VARCHAR(255) DEFAULT NULL,
    instagram_url VARCHAR(255) DEFAULT NULL,
    address TEXT NOT NULL,
    kecamatan VARCHAR(100) NOT NULL,
    kelurahan VARCHAR(120) NOT NULL DEFAULT '',
    bank_name VARCHAR(80) NOT NULL,
    bank_account_name VARCHAR(120) NOT NULL,
    bank_account_number VARCHAR(50) NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mp_shops_username (username),
    KEY idx_mp_shops_status (status),
    KEY idx_mp_shops_business_name (business_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_products (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    category VARCHAR(80) NOT NULL,
    price BIGINT UNSIGNED NOT NULL,
    stock INT UNSIGNED NOT NULL DEFAULT 0,
    image_path VARCHAR(255) DEFAULT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mp_products_shop_status (shop_id, status),
    KEY idx_mp_products_status (status),
    CONSTRAINT fk_mp_products_shop FOREIGN KEY (shop_id) REFERENCES mp_shops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_product_images (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mp_product_images_path (image_path),
    KEY idx_mp_product_images_product_order (product_id, sort_order, id),
    CONSTRAINT fk_mp_product_images_product FOREIGN KEY (product_id) REFERENCES mp_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_code VARCHAR(40) NOT NULL,
    shop_id BIGINT UNSIGNED NOT NULL,
    customer_name VARCHAR(120) NOT NULL,
    customer_phone VARCHAR(30) NOT NULL,
    shipping_address TEXT NOT NULL,
    kecamatan VARCHAR(100) NOT NULL,
    customer_note TEXT DEFAULT NULL,
    payment_method ENUM('transfer','cod') NOT NULL,
    payment_status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
    order_status ENUM('menunggu_pembayaran','pesanan_baru','diproses','dikirim','selesai','dibatalkan') NOT NULL,
    bank_name VARCHAR(80) DEFAULT NULL,
    bank_account_name VARCHAR(120) DEFAULT NULL,
    bank_account_number VARCHAR(50) DEFAULT NULL,
    total_amount BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mp_orders_order_code (order_code),
    KEY idx_mp_orders_shop_status (shop_id, order_status),
    KEY idx_mp_orders_created (created_at),
    CONSTRAINT fk_mp_orders_shop FOREIGN KEY (shop_id) REFERENCES mp_shops(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_order_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED DEFAULT NULL,
    product_name VARCHAR(160) NOT NULL,
    unit_price BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    subtotal BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_mp_order_items_order (order_id),
    CONSTRAINT fk_mp_order_items_order FOREIGN KEY (order_id) REFERENCES mp_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_mp_order_items_product FOREIGN KEY (product_id) REFERENCES mp_products(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_product_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    review_text VARCHAR(1500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mp_product_reviews_order_item (order_item_id),
    KEY idx_mp_product_reviews_product_created (product_id, created_at),
    CONSTRAINT fk_mp_product_reviews_product FOREIGN KEY (product_id) REFERENCES mp_products(id) ON DELETE CASCADE,
    CONSTRAINT fk_mp_product_reviews_order_item FOREIGN KEY (order_item_id) REFERENCES mp_order_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_admins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(60) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    setup_slot TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mp_admins_username (username),
    UNIQUE KEY uq_mp_admins_single_account (setup_slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_slides (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(180) NOT NULL DEFAULT '',
    caption VARCHAR(300) NOT NULL DEFAULT '',
    image_path VARCHAR(255) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mp_slides_active_order (is_active, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_site_logos (
    slot VARCHAR(20) NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (slot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_site_settings (
    setting_key VARCHAR(50) NOT NULL,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_conversations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel ENUM('buyer_shop', 'seller_admin') NOT NULL,
    shop_id BIGINT UNSIGNED DEFAULT NULL,
    contact_name VARCHAR(120) NOT NULL,
    contact_phone VARCHAR(30) NOT NULL,
    access_token_hash CHAR(64) DEFAULT NULL,
    last_message_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mp_conversations_access_token (access_token_hash),
    KEY idx_mp_conversations_shop_channel (shop_id, channel, last_message_at),
    KEY idx_mp_conversations_channel_latest (channel, last_message_at),
    CONSTRAINT fk_mp_conversations_shop FOREIGN KEY (shop_id) REFERENCES mp_shops(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mp_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    sender_type ENUM('buyer', 'seller', 'prospective', 'admin') NOT NULL,
    sender_id BIGINT UNSIGNED DEFAULT NULL,
    message_body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mp_messages_conversation_created (conversation_id, created_at, id),
    CONSTRAINT fk_mp_messages_conversation FOREIGN KEY (conversation_id) REFERENCES mp_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

ALTER TABLE mp_site_settings MODIFY setting_value TEXT NOT NULL;

INSERT IGNORE INTO mp_site_settings (setting_key, setting_value) VALUES
    ('app_name', 'FLOM Marketplace Flores Timur'),
    ('copyright_text', 'Pemerintah Kabupaten Flores Timur'),
    ('contact_intro', 'Kami menyediakan berbagai media yang dapat Anda hubungi untuk informasi layanan marketplace dan ekonomi kreatif Flores Timur.'),
    ('contact_work_hours', 'Senin-Jumat, 08.00-16.00 WITA'),
    ('contact_address', 'Alamat uji coba Flores Timur'),
    ('contact_email', 'uji@example.invalid'),
    ('contact_phone', '0000000000'),
    ('contact_map_url', 'https://www.google.com/maps?q=Flores+Timur&output=embed');
