USE db_dinkop;

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
