USE db_dinkop;

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
