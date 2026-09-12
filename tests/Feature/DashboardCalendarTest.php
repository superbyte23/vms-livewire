<?php

use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use Livewire\Livewire;

uses()->group('feature')->group('dashboard-calendar');

test('calendar defaults to the current month with today selected', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::components.calendar')
        ->assertSet('calendarMonth', now()->format('Y-m'))
        ->assertSet('selectedDate', now()->toDateString())
        ->assertSee('Visit Calendar')
        ->assertSee(now()->format('F Y'));
});

test('calendar month navigation changes the displayed month', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::components.calendar')
        ->call('nextMonth')
        ->assertSet('calendarMonth', now()->addMonth()->format('Y-m'))
        ->call('prevMonth')
        ->call('prevMonth')
        ->assertSet('calendarMonth', now()->subMonth()->format('Y-m'))
        ->call('goToCurrentMonth')
        ->assertSet('calendarMonth', now()->format('Y-m'))
        ->assertSet('selectedDate', now()->toDateString());
});

test('calendar weeks cover every day of the displayed month', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test('pages::components.calendar');
    $weeks = $component->get('calendarWeeks');

    $cells = collect($weeks)->flatten(1);
    $inMonth = $cells->where('inMonth', true);

    expect($inMonth->count())->toBe(now()->daysInMonth)
        ->and($inMonth->pluck('label')->sort()->values()->all())
        ->toBe(range(1, now()->daysInMonth));

    foreach ($weeks as $week) {
        expect($week)->toHaveCount(7);
    }
});

test('scheduled visits surface on their expected date', function () {
    $user = User::factory()->create();
    $visitor = Visitor::factory()->create(['firstname' => 'Calendar', 'lastname' => 'Carl', 'company' => 'Carl Co']);
    $this->actingAs($user);

    $date = now()->addDays(3)->toDateString();

    Visit::factory()->scheduled()->create([
        'visitor_id' => $visitor->id,
        'expected_date' => $date,
        'visit_type' => 'Interviewee',
        'purpose' => 'Final round',
        'host' => 'Helen',
    ]);

    $component = Livewire::test('pages::components.calendar')->call('selectDate', $date);

    $cell = collect($component->get('calendarWeeks'))->flatten(1)->firstWhere('date', $date);

    expect($cell['scheduled'])->toBe(1)
        ->and(collect($cell['events'])->pluck('name')->all())->toContain('Calendar Carl');

    $component
        ->assertSet('selectedDate', $date)
        ->assertSet('showDayModal', true)
        ->assertSee('Calendar Carl')
        ->assertSee('Carl Co')
        ->assertSee('Interviewee')
        ->assertSee('Final round')
        ->assertSee('Helen');
});

test('past visits are counted on their day', function () {
    $user = User::factory()->create();
    $visitor = Visitor::factory()->create();
    $this->actingAs($user);

    Visit::factory()->count(2)->create([
        'visitor_id' => $visitor->id,
        'created_at' => now(),
    ]);

    $component = Livewire::test('pages::components.calendar');
    $cell = collect($component->get('calendarWeeks'))->flatten(1)->firstWhere('date', now()->toDateString());

    expect($cell['visits'])->toBeGreaterThanOrEqual(2);
});

test('day modal shows who is on-site today', function () {
    $user = User::factory()->create();
    $visitor = Visitor::factory()->create(['firstname' => 'Onsite', 'lastname' => 'Olivia', 'company' => 'Onsite Inc']);
    $this->actingAs($user);

    Visit::factory()->create([
        'visitor_id' => $visitor->id,
        'status' => 'checked_in',
        'checked_in_at' => now(),
        'badge_number' => 'V-TEST-001',
    ]);

    Livewire::test('pages::components.calendar')
        ->call('selectDate', now()->toDateString())
        ->assertSet('showDayModal', true)
        ->assertSee('On-site now')
        ->assertSee('Onsite Olivia')
        ->assertSee('V-TEST-001');
});

test('cancelled scheduled visits do not inflate the calendar count', function () {
    $user = User::factory()->create();
    $visitor = Visitor::factory()->create();
    $this->actingAs($user);

    $date = now()->addDays(5)->toDateString();

    Visit::factory()->cancelled()->create([
        'visitor_id' => $visitor->id,
        'expected_date' => $date,
    ]);

    $component = Livewire::test('pages::components.calendar');
    $cell = collect($component->get('calendarWeeks'))->flatten(1)->firstWhere('date', $date);

    expect($cell['scheduled'])->toBe(0);
});
