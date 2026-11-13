# Entity relationship diagram

Application tables only. Framework tables (`cache`, `jobs`, `sessions`, `passkeys`, `password_reset_tokens`) and the spatie/laravel-permission tables (`roles`, `permissions`, and their pivots) are left out.

```mermaid
erDiagram
    users ||--o{ orders : "places (restrict on delete)"
    promo_codes |o--o{ orders : "applied to (null on delete)"
    orders ||--|{ order_items : "contains (cascade)"
    menu_items |o--o{ order_items : "snapshotted as (null on delete)"
    order_items ||--o{ order_item_modifiers : "has (cascade)"
    modifier_options |o--o{ order_item_modifiers : "snapshotted as (null on delete)"

    categories ||--o{ menu_items : "groups (restrict on delete)"
    menu_items ||--o{ menu_item_modifier_group : "cascade"
    modifier_groups ||--o{ menu_item_modifier_group : "cascade"
    modifier_groups ||--o{ modifier_options : "has (cascade)"

    users {
        bigint id PK
        string name
        string email UK
        string password
        timestamp email_verified_at "nullable"
    }

    categories {
        bigint id PK
        string name_en
        string name_zh
        string slug UK
        uint sort_order
        bool is_active
    }

    menu_items {
        bigint id PK
        bigint category_id FK
        string name_en
        string name_zh
        text description "nullable"
        uint price_cents
        string photo_path "nullable"
        string tax_category "standard | carbonated"
        utinyint prep_minutes
        bool is_available
        uint sort_order
    }

    modifier_groups {
        bigint id PK
        string name_en
        string name_zh
        bool is_required
        utinyint min_select
        utinyint max_select
        uint sort_order
    }

    modifier_options {
        bigint id PK
        bigint modifier_group_id FK
        string name_en
        string name_zh
        uint price_delta_cents
        bool is_default
        bool is_available
        uint sort_order
    }

    menu_item_modifier_group {
        bigint menu_item_id FK
        bigint modifier_group_id FK
        uint sort_order
    }

    promo_codes {
        bigint id PK
        string code UK "32"
        string type "PromoType: percent | fixed"
        uint value "percent 1-100, or cents"
        timestamp expires_at "nullable"
        uint max_uses "nullable = unlimited"
        uint used_count
        bool is_active
    }

    orders {
        bigint id PK
        string order_number UK "TA-1042"
        bigint user_id FK
        bigint promo_code_id FK "nullable"
        string promo_code "nullable, snapshot"
        string status "OrderStatus"
        string pickup_type "PickupType: asap | scheduled"
        timestamp pickup_at "nullable, UTC"
        uint subtotal_cents
        uint discount_cents
        uint gst_cents
        uint pst_cents
        uint total_cents
        string idempotency_key UK "64"
        string stripe_session_id UK "nullable"
        timestamp paid_at "nullable"
        timestamp started_at "nullable"
        timestamp ready_at "nullable"
        timestamp picked_up_at "nullable"
        timestamp cancelled_at "nullable"
    }

    order_items {
        bigint id PK
        bigint order_id FK
        bigint menu_item_id FK "nullable"
        string name_en "snapshot"
        string name_zh "snapshot"
        string tax_category "snapshot"
        uint unit_price_cents "snapshot"
        usmallint quantity
    }

    order_item_modifiers {
        bigint id PK
        bigint order_item_id FK
        bigint modifier_option_id FK "nullable"
        string group_name_en "snapshot"
        string group_name_zh "snapshot"
        string name_en "snapshot"
        string name_zh "snapshot"
        uint price_delta_cents "snapshot"
    }

    store_hours {
        bigint id PK
        utinyint day_of_week UK "0 = Sunday ... 6 = Saturday"
        time opens_at "nullable, Vancouver wall clock"
        time closes_at "nullable, Vancouver wall clock"
        bool is_closed
        usmallint ordering_cutoff_minutes
    }
```

## Notes

- All `*_cents` columns are unsigned integers. Money is formatted only in views.
- All `timestamp` columns are UTC. `store_hours.opens_at` and `closes_at` are the exception: they are America/Vancouver wall-clock times, so DST changes don't shift them.
- Orders snapshot names, tax category, prices, and the promo code at order time. Deleting a menu item, modifier option, or promo code sets the foreign key to null and leaves the snapshot unchanged.
- Every table except `order_item_modifiers` and `menu_item_modifier_group` also has `created_at` and `updated_at`. They're left out above to keep the diagram short.
- Index on `orders (status, created_at)` for the barista board and order history.
