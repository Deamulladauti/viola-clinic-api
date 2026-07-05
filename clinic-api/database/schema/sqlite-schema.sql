CREATE TABLE IF NOT EXISTS "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE IF NOT EXISTS "users"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "email" varchar not null,
  "email_verified_at" datetime,
  "password" varchar not null,
  "remember_token" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "phone" varchar,
  "is_active" tinyint(1) not null default '1',
  "avatar_path" varchar,
  "address_line1" varchar,
  "address_line2" varchar,
  "city" varchar,
  "country_code" varchar,
  "preferred_language" varchar not null default 'en',
  "marketing_opt_in" tinyint(1) not null default '0',
  "notification_prefs" text,
  "pref_lang" varchar not null default 'en',
  "notifications_enabled" tinyint(1) not null default '1',
  "deleted_at" datetime
);
CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
CREATE TABLE IF NOT EXISTS "password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE IF NOT EXISTS "sessions"(
  "id" varchar not null,
  "user_id" integer,
  "ip_address" varchar,
  "user_agent" text,
  "payload" text not null,
  "last_activity" integer not null,
  primary key("id")
);
CREATE INDEX "sessions_user_id_index" on "sessions"("user_id");
CREATE INDEX "sessions_last_activity_index" on "sessions"("last_activity");
CREATE TABLE IF NOT EXISTS "cache"(
  "key" varchar not null,
  "value" text not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE TABLE IF NOT EXISTS "cache_locks"(
  "key" varchar not null,
  "owner" varchar not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE TABLE IF NOT EXISTS "jobs"(
  "id" integer primary key autoincrement not null,
  "queue" varchar not null,
  "payload" text not null,
  "attempts" integer not null,
  "reserved_at" integer,
  "available_at" integer not null,
  "created_at" integer not null
);
CREATE INDEX "jobs_queue_index" on "jobs"("queue");
CREATE TABLE IF NOT EXISTS "job_batches"(
  "id" varchar not null,
  "name" varchar not null,
  "total_jobs" integer not null,
  "pending_jobs" integer not null,
  "failed_jobs" integer not null,
  "failed_job_ids" text not null,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer not null,
  "finished_at" integer,
  primary key("id")
);
CREATE TABLE IF NOT EXISTS "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" text not null,
  "queue" text not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE IF NOT EXISTS "personal_access_tokens"(
  "id" integer primary key autoincrement not null,
  "tokenable_type" varchar not null,
  "tokenable_id" integer not null,
  "name" text not null,
  "token" varchar not null,
  "abilities" text,
  "last_used_at" datetime,
  "expires_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "personal_access_tokens_tokenable_type_tokenable_id_index" on "personal_access_tokens"(
  "tokenable_type",
  "tokenable_id"
);
CREATE UNIQUE INDEX "personal_access_tokens_token_unique" on "personal_access_tokens"(
  "token"
);
CREATE INDEX "personal_access_tokens_expires_at_index" on "personal_access_tokens"(
  "expires_at"
);
CREATE UNIQUE INDEX "users_phone_unique" on "users"("phone");
CREATE INDEX "users_is_active_index" on "users"("is_active");
CREATE TABLE IF NOT EXISTS "permissions"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "guard_name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "permissions_name_guard_name_unique" on "permissions"(
  "name",
  "guard_name"
);
CREATE TABLE IF NOT EXISTS "roles"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "guard_name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "roles_name_guard_name_unique" on "roles"(
  "name",
  "guard_name"
);
CREATE TABLE IF NOT EXISTS "model_has_permissions"(
  "permission_id" integer not null,
  "model_type" varchar not null,
  "model_id" integer not null,
  foreign key("permission_id") references "permissions"("id") on delete cascade,
  primary key("permission_id", "model_id", "model_type")
);
CREATE INDEX "model_has_permissions_model_id_model_type_index" on "model_has_permissions"(
  "model_id",
  "model_type"
);
CREATE TABLE IF NOT EXISTS "model_has_roles"(
  "role_id" integer not null,
  "model_type" varchar not null,
  "model_id" integer not null,
  foreign key("role_id") references "roles"("id") on delete cascade,
  primary key("role_id", "model_id", "model_type")
);
CREATE INDEX "model_has_roles_model_id_model_type_index" on "model_has_roles"(
  "model_id",
  "model_type"
);
CREATE TABLE IF NOT EXISTS "role_has_permissions"(
  "permission_id" integer not null,
  "role_id" integer not null,
  foreign key("permission_id") references "permissions"("id") on delete cascade,
  foreign key("role_id") references "roles"("id") on delete cascade,
  primary key("permission_id", "role_id")
);
CREATE TABLE IF NOT EXISTS "service_categories"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "slug" varchar not null,
  "description" text,
  "is_active" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "image_path" varchar
);
CREATE UNIQUE INDEX "service_categories_name_unique" on "service_categories"(
  "name"
);
CREATE UNIQUE INDEX "service_categories_slug_unique" on "service_categories"(
  "slug"
);
CREATE INDEX "service_categories_is_active_index" on "service_categories"(
  "is_active"
);
CREATE TABLE IF NOT EXISTS "services"(
  "id" integer primary key autoincrement not null,
  "service_category_id" integer not null,
  "name" varchar not null,
  "slug" varchar not null,
  "description" text,
  "duration_minutes" integer not null,
  "price" numeric not null,
  "is_active" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  "image_path" varchar,
  "is_bookable" tinyint(1) not null default '1',
  "name_i18n" text,
  "short_description_i18n" text,
  "description_i18n" text,
  "prep_instructions" text,
  "short_description" text,
  "is_package" tinyint(1) not null default '0',
  "total_sessions" integer,
  "total_minutes" integer,
  foreign key("service_category_id") references "service_categories"("id") on delete cascade
);
CREATE INDEX "services_service_category_id_is_active_index" on "services"(
  "service_category_id",
  "is_active"
);
CREATE UNIQUE INDEX "services_slug_unique" on "services"("slug");
CREATE TABLE IF NOT EXISTS "tags"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "slug" varchar not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "tags_slug_unique" on "tags"("slug");
CREATE TABLE IF NOT EXISTS "service_tag"(
  "id" integer primary key autoincrement not null,
  "service_id" integer not null,
  "tag_id" integer not null,
  foreign key("service_id") references "services"("id") on delete cascade,
  foreign key("tag_id") references "tags"("id") on delete cascade
);
CREATE UNIQUE INDEX "service_tag_service_id_tag_id_unique" on "service_tag"(
  "service_id",
  "tag_id"
);
CREATE TABLE IF NOT EXISTS "service_staff"(
  "id" integer primary key autoincrement not null,
  "service_id" integer not null,
  "staff_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("service_id") references "services"("id") on delete cascade,
  foreign key("staff_id") references "staff"("id") on delete cascade
);
CREATE UNIQUE INDEX "service_staff_service_id_staff_id_unique" on "service_staff"(
  "service_id",
  "staff_id"
);
CREATE TABLE IF NOT EXISTS "staff_schedules"(
  "id" integer primary key autoincrement not null,
  "staff_id" integer not null,
  "weekday" integer not null,
  "start_time" time not null,
  "end_time" time not null,
  "is_active" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("staff_id") references "staff"("id") on delete cascade
);
CREATE INDEX "staff_schedules_staff_id_weekday_index" on "staff_schedules"(
  "staff_id",
  "weekday"
);
CREATE TABLE IF NOT EXISTS "staff_time_off"(
  "id" integer primary key autoincrement not null,
  "staff_id" integer not null,
  "date" date not null,
  "start_time" time,
  "end_time" time,
  "reason" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("staff_id") references "staff"("id") on delete cascade
);
CREATE INDEX "staff_time_off_staff_id_date_index" on "staff_time_off"(
  "staff_id",
  "date"
);
CREATE TABLE IF NOT EXISTS "staff"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "email" varchar,
  "phone" varchar,
  "is_active" tinyint(1) not null default('1'),
  "created_at" datetime,
  "updated_at" datetime,
  "user_id" integer,
  foreign key("user_id") references "users"("id") on delete set null
);
CREATE UNIQUE INDEX "staff_email_unique" on "staff"("email");
CREATE UNIQUE INDEX "staff_user_id_unique" on "staff"("user_id");
CREATE TABLE IF NOT EXISTS "service_packages"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "service_id" integer not null,
  "service_name" varchar,
  "snapshot_total_sessions" integer,
  "snapshot_total_minutes" integer,
  "price_paid" numeric not null default '0',
  "currency" varchar not null default 'EUR',
  "remaining_sessions" integer,
  "remaining_minutes" integer,
  "status" varchar not null default 'active',
  "starts_on" date,
  "expires_on" date,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "price_total" numeric,
  "amount_paid" numeric not null default '0',
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("service_id") references "services"("id") on delete restrict
);
CREATE INDEX "service_packages_user_id_status_index" on "service_packages"(
  "user_id",
  "status"
);
CREATE INDEX "service_packages_service_id_status_index" on "service_packages"(
  "service_id",
  "status"
);
CREATE INDEX "service_packages_expires_on_index" on "service_packages"(
  "expires_on"
);
CREATE TABLE IF NOT EXISTS "notifications"(
  "id" varchar not null,
  "type" varchar not null,
  "notifiable_type" varchar not null,
  "notifiable_id" integer not null,
  "data" text not null,
  "read_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  primary key("id")
);
CREATE INDEX "notifications_notifiable_type_notifiable_id_index" on "notifications"(
  "notifiable_type",
  "notifiable_id"
);
CREATE TABLE IF NOT EXISTS "appointment_logs"(
  "id" integer primary key autoincrement not null,
  "appointment_id" integer not null,
  "user_id" integer,
  "action" varchar not null,
  "meta" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("appointment_id") references "appointments"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete set null
);
CREATE TABLE IF NOT EXISTS "appointments"(
  "id" integer primary key autoincrement not null,
  "service_id" integer not null,
  "staff_id" integer,
  "date" date not null,
  "starts_at" time not null,
  "duration_minutes" integer not null,
  "price" numeric not null,
  "customer_name" varchar not null,
  "customer_phone" varchar,
  "customer_email" varchar,
  "status" varchar not null default('pending'),
  "notes" text,
  "reference_code" varchar not null,
  "deleted_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "user_id" integer,
  "admin_notes" text,
  "service_package_id" integer,
  foreign key("user_id") references users("id") on delete set null on update no action,
  foreign key("service_id") references services("id") on delete restrict on update cascade,
  foreign key("staff_id") references staff("id") on delete set null on update no action,
  foreign key("service_package_id") references "service_packages"("id") on delete set null
);
CREATE INDEX "appointments_date_starts_at_index" on "appointments"(
  "date",
  "starts_at"
);
CREATE UNIQUE INDEX "appointments_reference_code_unique" on "appointments"(
  "reference_code"
);
CREATE INDEX "appointments_service_id_date_index" on "appointments"(
  "service_id",
  "date"
);
CREATE INDEX "appointments_staff_id_date_index" on "appointments"(
  "staff_id",
  "date"
);
CREATE INDEX "appointments_status_index" on "appointments"("status");
CREATE INDEX "appointments_staff_id_idx" on "appointments"("staff_id");
CREATE INDEX "appointments_staff_date_start_idx" on "appointments"(
  "staff_id",
  "date",
  "starts_at"
);
CREATE INDEX "packages_user_service_status_idx" on "service_packages"(
  "user_id",
  "service_id",
  "status"
);
CREATE TABLE IF NOT EXISTS "package_payments"(
  "id" integer primary key autoincrement not null,
  "service_package_id" integer,
  "appointment_id" integer,
  "user_id" integer,
  "staff_id" integer,
  "admin_id" integer,
  "method" varchar not null,
  "amount" numeric not null,
  "currency" varchar not null default('EUR'),
  "notes" text,
  "voided_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("admin_id") references users("id") on delete set null on update no action,
  foreign key("staff_id") references staff("id") on delete set null on update no action,
  foreign key("user_id") references users("id") on delete set null on update no action,
  foreign key("appointment_id") references appointments("id") on delete set null on update no action,
  foreign key("service_package_id") references service_packages("id") on delete cascade on update no action
);
CREATE INDEX "package_payments_appointment_id_index" on "package_payments"(
  "appointment_id"
);
CREATE INDEX "package_payments_service_package_id_index" on "package_payments"(
  "service_package_id"
);
CREATE TABLE IF NOT EXISTS "package_logs"(
  "id" integer primary key autoincrement not null,
  "service_package_id" integer not null,
  "staff_id" integer,
  "appointment_id" integer,
  "appointment_ref" varchar,
  "used_sessions" integer,
  "used_minutes" integer,
  "used_at" datetime not null default(CURRENT_TIMESTAMP),
  "note" text,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  foreign key("staff_id") references staff("id") on delete set null on update no action,
  foreign key("service_package_id") references service_packages("id") on delete cascade on update no action
);
CREATE INDEX "package_logs_service_package_id_used_at_index" on "package_logs"(
  "service_package_id",
  "used_at"
);
CREATE INDEX "package_logs_staff_id_used_at_index" on "package_logs"(
  "staff_id",
  "used_at"
);
CREATE TABLE IF NOT EXISTS "expense_categories"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "slug" varchar not null,
  "is_active" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "expense_categories_slug_unique" on "expense_categories"(
  "slug"
);
CREATE TABLE IF NOT EXISTS "expenses"(
  "id" integer primary key autoincrement not null,
  "expense_category_id" integer not null,
  "amount" numeric not null,
  "expense_date" date not null,
  "note" text,
  "entered_by" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("expense_category_id") references "expense_categories"("id") on delete restrict on update cascade,
  foreign key("entered_by") references "users"("id") on delete set null on update cascade
);
CREATE INDEX "expenses_expense_date_index" on "expenses"("expense_date");

INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_users_table',1);
INSERT INTO migrations VALUES(2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO migrations VALUES(3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO migrations VALUES(4,'2025_10_05_164131_create_personal_access_tokens_table',1);
INSERT INTO migrations VALUES(5,'2025_10_07_143327_alter_users_add_flags',1);
INSERT INTO migrations VALUES(6,'2025_10_07_143448_create_permission_tables',1);
INSERT INTO migrations VALUES(7,'2025_10_07_153258_create_service_categories_table',1);
INSERT INTO migrations VALUES(8,'2025_10_08_125759_create_services_table',1);
INSERT INTO migrations VALUES(9,'2025_10_08_182721_create_tags_table',1);
INSERT INTO migrations VALUES(10,'2025_10_08_182747_create_service_tag_table',1);
INSERT INTO migrations VALUES(11,'2025_10_08_190946_add_image_path_to_service_categories',1);
INSERT INTO migrations VALUES(12,'2025_10_08_191036_add_image_path_to_services',1);
INSERT INTO migrations VALUES(13,'2025_10_20_184250_create_appointments_table',1);
INSERT INTO migrations VALUES(14,'2025_10_24_160109_create_staff_domain',1);
INSERT INTO migrations VALUES(15,'2025_10_28_220229_add_user_id_to_staff_table',1);
INSERT INTO migrations VALUES(16,'2025_10_29_181002_add_profile_fields_to_users_table',1);
INSERT INTO migrations VALUES(17,'2025_11_02_213254_add_i18n_and_ux_fields_to_services_table',1);
INSERT INTO migrations VALUES(18,'2025_11_02_221354_add_phase1_fields_to_services_table',1);
INSERT INTO migrations VALUES(19,'2025_11_03_213316_add_package_fields_to_services_table',1);
INSERT INTO migrations VALUES(20,'2025_11_03_213338_create_service_packages_table',1);
INSERT INTO migrations VALUES(21,'2025_11_03_213403_create_package_logs_table',1);
INSERT INTO migrations VALUES(22,'2025_11_04_140546_create_notifications_table',1);
INSERT INTO migrations VALUES(23,'2025_11_04_140727_add_lang_email_to_users_table',1);
INSERT INTO migrations VALUES(24,'2025_11_05_212639_add_user_id_to_appointments_table',1);
INSERT INTO migrations VALUES(25,'2025_11_05_215512_create_appointment_logs_table',1);
INSERT INTO migrations VALUES(26,'2025_11_05_224657_add_admin_notes_to_appointments_table',1);
INSERT INTO migrations VALUES(27,'2025_11_07_144943_add_money_fields_to_customer_packages',1);
INSERT INTO migrations VALUES(28,'2025_11_07_145059_create_package_payments_table',1);
INSERT INTO migrations VALUES(29,'2025_11_07_145206_add_customer_package_id_to_appointments',1);
INSERT INTO migrations VALUES(30,'2025_11_12_223352_fix_staff_fk_on_appointments',1);
INSERT INTO migrations VALUES(31,'2025_11_12_223427_add_composite_index_for_overlap',1);
INSERT INTO migrations VALUES(32,'2025_11_12_223434_admin_notes_rollback_fix',1);
INSERT INTO migrations VALUES(33,'2025_11_12_223442_package_hot_index',1);
INSERT INTO migrations VALUES(34,'2025_11_17_185143_add_notifications_enabled_to_users_table',2);
INSERT INTO migrations VALUES(35,'2025_11_17_194705_add_soft_deletes_to_users_table',3);
INSERT INTO migrations VALUES(36,'2025_11_19_105429_add_unique_index_to_users_phone',4);
INSERT INTO migrations VALUES(37,'2025_12_04_130301_add_price_total_to_service_packages_table',5);
INSERT INTO migrations VALUES(38,'2025_12_04_143220_make_service_package_id_nullable_on_package_payments_table',6);
INSERT INTO migrations VALUES(39,'2025_12_05_145517_make_package_logs_columns_nullable',7);
INSERT INTO migrations VALUES(40,'2026_03_30_214746_create_expense_categories_table',8);
INSERT INTO migrations VALUES(41,'2026_03_30_215616_create_expenses_table',8);
