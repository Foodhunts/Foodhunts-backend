<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Allow a delivery to exist without an order.
 *
 * Two real cases need this, and both were already handled in code before the
 * schema allowed them:
 *
 *   - DeliveryEventProcessor stores an event whose delivery it cannot match, so
 *     an orphan is visible rather than discarded. Adopting such an event means
 *     writing a delivery row with no local order.
 *   - An order deleted while its delivery is still live would otherwise cascade
 *     the delivery away, destroying the record of a job a rider may still be
 *     carrying.
 *
 * The foreign key is kept, so a non-null order_id must still reference a real
 * order; it simply no longer has to be present. On delete it nulls rather than
 * cascades, for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE deliveries ALTER COLUMN order_id DROP NOT NULL');

        DB::statement('ALTER TABLE deliveries DROP CONSTRAINT IF EXISTS deliveries_order_id_foreign');

        DB::statement(
            'ALTER TABLE deliveries ADD CONSTRAINT deliveries_order_id_foreign '
            .'FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DELETE FROM deliveries WHERE order_id IS NULL');

        DB::statement('ALTER TABLE deliveries DROP CONSTRAINT IF EXISTS deliveries_order_id_foreign');

        DB::statement(
            'ALTER TABLE deliveries ADD CONSTRAINT deliveries_order_id_foreign '
            .'FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE'
        );

        DB::statement('ALTER TABLE deliveries ALTER COLUMN order_id SET NOT NULL');
    }
};
