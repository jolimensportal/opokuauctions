import { Migration } from "@medusajs/framework/mikro-orm/migrations";

export class Migration20261015180147 extends Migration {

  override async up(): Promise<void> {
    this.addSql(`create table if not exists "ledger_entry" ("id" text not null, "wallet_id" text not null, "user_id" text not null, "currency_code" text not null, "type" text not null, "amount" integer not null, "balance_after" integer not null, "reference_type" text null, "reference_id" text null, "note" text null, "created_at" timestamptz not null default now(), "updated_at" timestamptz not null default now(), "deleted_at" timestamptz null, constraint "ledger_entry_pkey" primary key ("id"));`);
    this.addSql(`CREATE INDEX IF NOT EXISTS "IDX_ledger_entry_deleted_at" ON "ledger_entry" ("deleted_at") WHERE deleted_at IS NULL;`);

    this.addSql(`create table if not exists "wallet" ("id" text not null, "user_id" text not null, "currency_code" text not null, "balance_available" integer not null default 0, "balance_held" integer not null default 0, "version" integer not null default 1, "created_at" timestamptz not null default now(), "updated_at" timestamptz not null default now(), "deleted_at" timestamptz null, constraint "wallet_pkey" primary key ("id"));`);
    this.addSql(`CREATE INDEX IF NOT EXISTS "IDX_wallet_deleted_at" ON "wallet" ("deleted_at") WHERE deleted_at IS NULL;`);

    // Money can never be negative. The service refuses to let it happen; the
    // database refuses too, so no future bug can mint debt.
    this.addSql(`alter table "wallet" add constraint "CHK_wallet_available_non_negative" check ("balance_available" >= 0);`);
    this.addSql(`alter table "wallet" add constraint "CHK_wallet_held_non_negative" check ("balance_held" >= 0);`);
  }
  override async down(): Promise<void> {
    this.addSql(`drop table if exists "ledger_entry" cascade;`);

    this.addSql(`drop table if exists "wallet" cascade;`);
  }

}
