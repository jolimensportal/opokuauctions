import { Migration } from "@medusajs/framework/mikro-orm/migrations";

export class Migration20261015180146 extends Migration {

  override async up(): Promise<void> {
    this.addSql(`create table if not exists "bid" ("id" text not null, "lot_id" text not null, "bidder_id" text not null, "bidder_email" text not null, "amount" integer not null, "currency_code" text not null default 'usd', "status" text check ("status" in ('ACCEPTED', 'OUTBID', 'WINNING', 'RETRACTED')) not null default 'ACCEPTED', "caused_extension" boolean not null default false, "idempotency_key" text null, "created_at" timestamptz not null default now(), "updated_at" timestamptz not null default now(), "deleted_at" timestamptz null, constraint "bid_pkey" primary key ("id"));`);
    this.addSql(`CREATE INDEX IF NOT EXISTS "IDX_bid_deleted_at" ON "bid" ("deleted_at") WHERE deleted_at IS NULL;`);

    this.addSql(`create table if not exists "lot" ("id" text not null, "vin" text null, "year" integer not null, "make" text not null, "model" text not null, "trim" text null, "city" text not null, "photos" jsonb not null default '[]', "condition" text not null default 'unknown', "odometer" integer null, "damage_primary" text null, "currency_code" text not null default 'usd', "starting_bid" integer not null, "reserve_price" integer not null default 0, "bid_increment" integer not null, "starts_at" timestamptz not null, "ends_at" timestamptz not null, "status" text check ("status" in ('UPCOMING', 'LIVE', 'ENDED', 'SOLD', 'RESERVE_NOT_MET', 'CANCELLED')) not null default 'UPCOMING', "current_bid" integer not null default 0, "highest_bidder_id" text null, "bid_count" integer not null default 0, "extensions" integer not null default 0, "created_at" timestamptz not null default now(), "updated_at" timestamptz not null default now(), "deleted_at" timestamptz null, constraint "lot_pkey" primary key ("id"));`);
    this.addSql(`CREATE INDEX IF NOT EXISTS "IDX_lot_deleted_at" ON "lot" ("deleted_at") WHERE deleted_at IS NULL;`);

    // A retried request must never create a second bid. The service checks
    // first, but only a unique index survives a concurrent race.
    this.addSql(`create unique index if not exists "IDX_bid_idempotency_key" on "bid" ("idempotency_key") where "idempotency_key" is not null and "deleted_at" is null;`);
  }

  override async down(): Promise<void> {
    this.addSql(`drop table if exists "bid" cascade;`);

    this.addSql(`drop table if exists "lot" cascade;`);
  }

}
