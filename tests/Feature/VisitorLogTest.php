<?php

use App\Models\Visitor;
use App\Models\VisitorLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('feature')->group('settings');

test('change status toggles visitor status', function () {
    $visitor = Visitor::factory()->create();
    $log = VisitorLog::factory()->create([
        'visitor_id' => $visitor->id,
        'status' => 'checked_in',
        'checked_in_at' => now(),
    ]);

    Livewire::test('pages::visitor-logs')
        ->call('changeStatus', $log->id);

    $log->refresh();

    expect($log->status)->toBe('checked_out')
        ->and($log->checked_out_at)->not->toBeNull();
});

test('change status toggles back to checked in', function () {
    $visitor = Visitor::factory()->create();
    $log = VisitorLog::factory()->create([
        'visitor_id' => $visitor->id,
        'status' => 'checked_out',
        'checked_in_at' => now()->subHours(2),
        'checked_out_at' => now(),
    ]);

    Livewire::test('pages::visitor-logs')
        ->call('changeStatus', $log->id);

    $log->refresh();

    expect($log->status)->toBe('checked_in')
        ->and($log->checked_out_at)->toBeNull();
});

test('status badge updates after change status', function () {
    $visitor = Visitor::factory()->create();
    $log = VisitorLog::factory()->create([
        'visitor_id' => $visitor->id,
        'status' => 'checked_in',
        'checked_in_at' => now(),
    ]);

    Livewire::test('pages::visitor-logs')
        ->assertSee('On-site')
        ->call('changeStatus', $log->id)
        ->assertSee('Checked Out');

    $log->refresh();

    expect($log->status)->toBe('checked_out');
});

test('delete visitor log soft-deletes the record', function () {
    $visitor = Visitor::factory()->create();
    $log = VisitorLog::factory()->create([
        'visitor_id' => $visitor->id,
    ]);

    Livewire::test('pages::visitor-logs')
        ->call('confirmDelete', $log->id)
        ->assertSet('showDeleteModal', true)
        ->call('deleteLog');

    expect(VisitorLog::find($log->id))->toBeNull();

    $this->assertSoftDeleted($log);
});

test('view visitor log opens the modal', function () {
    $visitor = Visitor::factory()->create();
    $log = VisitorLog::factory()->create([
        'visitor_id' => $visitor->id,
    ]);

    Livewire::test('pages::visitor-logs')
        ->call('viewLog', $log->id)
        ->assertSet('showViewModal', true)
        ->assertSee($visitor->name);
});
