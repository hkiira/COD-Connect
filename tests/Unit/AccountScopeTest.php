<?php

namespace Tests\Unit;

use App\Models\Concerns\BelongsToAccount;
use App\Support\AccountContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScopedThing extends Model
{
    use BelongsToAccount;

    protected $table = 'scoped_things';
    protected $guarded = [];
    public $timestamps = false;
}

class AccountScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.default', 'sqlite_scope_test');
        Config::set('database.connections.sqlite_scope_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        DB::purge('sqlite_scope_test');

        Schema::create('scoped_things', function ($table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->string('name');
        });

        AccountContext::flush();
    }

    protected function tearDown(): void
    {
        AccountContext::flush();
        parent::tearDown();
    }

    public function test_queries_are_limited_to_the_current_account(): void
    {
        AccountContext::run(null, function () {
            ScopedThing::create(['account_id' => 1, 'name' => 'mine']);
            ScopedThing::create(['account_id' => 2, 'name' => 'theirs']);
        });

        $names = AccountContext::run(1, fn () => ScopedThing::pluck('name')->all());

        $this->assertSame(['mine'], $names);
    }

    public function test_other_account_rows_are_invisible_by_id(): void
    {
        $theirId = AccountContext::run(null, fn () => ScopedThing::create(['account_id' => 2, 'name' => 'theirs'])->id);

        $this->assertNull(AccountContext::run(1, fn () => ScopedThing::find($theirId)));
        $this->assertNotNull(AccountContext::run(2, fn () => ScopedThing::find($theirId)));
    }

    public function test_new_rows_inherit_the_current_account(): void
    {
        $thing = AccountContext::run(7, fn () => ScopedThing::create(['name' => 'auto']));

        $this->assertSame(7, (int) $thing->account_id);
    }

    public function test_no_scope_without_account_context(): void
    {
        AccountContext::run(null, function () {
            ScopedThing::create(['account_id' => 1, 'name' => 'a']);
            ScopedThing::create(['account_id' => 2, 'name' => 'b']);
        });

        $this->assertCount(2, AccountContext::run(null, fn () => ScopedThing::all()));
    }

    public function test_kill_switch_disables_the_scope(): void
    {
        AccountContext::run(null, function () {
            ScopedThing::create(['account_id' => 1, 'name' => 'a']);
            ScopedThing::create(['account_id' => 2, 'name' => 'b']);
        });

        Config::set('tenancy.enforce', false);

        $this->assertCount(2, AccountContext::run(1, fn () => ScopedThing::all()));
    }
}
