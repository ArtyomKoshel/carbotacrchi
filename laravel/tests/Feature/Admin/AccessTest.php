<?php

namespace Tests\Feature\Admin;

use App\Filament\Auth\Login;
use App\Filament\Pages\Logs;
use App\Filament\Resources\AdminUsers\AdminUserResource;
use App\Filament\Resources\AdminUsers\Pages\ListAdminUsers;
use App\Models\AdminUser;
use Database\Factories\AdminUserFactory;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** Sign-in, roles, page permissions and the old addresses. */
class AccessTest extends AdminTestCase
{
    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/lots')->assertRedirect('/admin/login');
    }

    public function test_panel_theme_follows_the_scheme_of_the_request_behind_a_proxy(): void
    {
        $html = $this->get('/admin/login', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('#href="https://[^"]+/css/filament/panel/theme\.css#', $html);
        $this->assertStringNotContainsString('href="http://', $html, 'an http stylesheet is blocked on an https page');
    }

    public function test_existing_admin_signs_in_with_username_and_password(): void
    {
        $admin = $this->super(['username' => 'boss']);

        Livewire::test(Login::class)
            ->fillForm(['username' => 'boss', 'password' => AdminUserFactory::PASSWORD])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_wrong_password_is_refused(): void
    {
        $this->super(['username' => 'boss']);

        Livewire::test(Login::class)
            ->fillForm(['username' => 'boss', 'password' => 'wrong-password'])
            ->call('authenticate')
            ->assertHasFormErrors(['username']);

        $this->assertGuest('admin');
    }

    public function test_unknown_user_is_refused(): void
    {
        Livewire::test(Login::class)
            ->fillForm(['username' => 'nobody', 'password' => AdminUserFactory::PASSWORD])
            ->call('authenticate')
            ->assertHasFormErrors(['username']);

        $this->assertGuest('admin');
    }

    public function test_limited_admin_opens_only_allowed_pages(): void
    {
        $this->signIn($this->limited(['lots-browse', 'logs']));

        $this->get('/admin/lots')->assertOk();
        $this->get('/admin/logs')->assertOk();

        foreach (['/admin', '/admin/platforms/encar', '/admin/translations', '/admin/translation-review', '/admin/field-coverage',
            '/admin/lot-changes', '/admin/search-settings', '/admin/pickers', '/admin/admin-users', '/admin/notifications',
            '/admin/encar-options'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_limited_navigation_hides_pages_that_are_not_allowed(): void
    {
        $this->signIn($this->limited(['lots-browse', 'logs']));

        $labels = collect(Filament::getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->map(fn ($item) => (string) $item->getLabel())
            ->all();

        $this->assertContains('Лоты', $labels);
        $this->assertContains('Логи', $labels);
        foreach (['Обзор', 'Encar', 'Переводы', 'Настройки поиска', 'Доступ к админке', 'Уведомления', 'Подборщики'] as $hidden) {
            $this->assertNotContains($hidden, $labels, "{$hidden} must be hidden for this limited admin");
        }
    }

    public function test_platform_tabs_follow_permissions(): void
    {
        $this->signIn($this->limited(['jobs']));

        $this->get('/admin/platforms/encar')->assertOk()->assertSee('Прогоны')->assertDontSee('Правила отбора');
        // a tab the role may not open falls back to an allowed one
        $this->get('/admin/platforms/encar?tab=rules')->assertOk()->assertDontSee('Новое правило');
    }

    public function test_limited_admin_cannot_manage_admins_or_clear_logs(): void
    {
        $this->signIn($this->limited(['logs']));

        $this->get('/admin/admin-users')->assertForbidden();
        Livewire::test(Logs::class)
            ->assertActionHidden('clearParser')
            ->assertActionHidden('clearJobs');
    }

    public function test_cannot_delete_yourself_or_the_last_super_admin(): void
    {
        $me = $this->signIn();
        $other = AdminUserFactory::new()->limited()->create();

        $this->assertFalse(AdminUserResource::canDelete($me), 'yourself');
        $this->assertTrue(AdminUserResource::canDelete($other));

        Livewire::test(ListAdminUsers::class)
            ->assertActionHidden(TestAction::make('delete')->table($me))
            ->assertActionVisible(TestAction::make('delete')->table($other));

        // two supers: each may delete the other, but never the last one left
        $second = $this->super();
        $this->assertTrue(AdminUserResource::canDelete($second));
        // migrations seed an admin too: leave $second as the only super
        AdminUser::whereKeyNot($second->id)->update(['role' => 'limited']);
        $this->assertFalse(AdminUserResource::canDelete($second->fresh()), 'last super admin');
    }

    public function test_hidden_delete_cannot_be_fired_by_hand(): void
    {
        $me = $this->signIn();

        Livewire::test(ListAdminUsers::class)
            ->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => (string) $me->id])
            ->assertSet('mountedActions', []);

        $this->assertNotNull($me->fresh(), 'still there');
    }

    public function test_tab_components_check_their_own_permission(): void
    {
        $this->signIn($this->limited(['jobs']));

        Livewire::test(\App\Livewire\Platform\Runs::class, ['source' => 'encar'])->assertOk();
        Livewire::test(\App\Livewire\Platform\Rules::class, ['source' => 'encar'])->assertForbidden();
        Livewire::test(\App\Livewire\Platform\Schedule::class, ['source' => 'encar'])->assertForbidden();
        Livewire::test(\App\Livewire\Platform\SkipLog::class, ['source' => 'encar'])->assertForbidden();
    }

    public function test_unknown_platform_is_not_found(): void
    {
        $this->signIn();
        $this->get('/admin/platforms/nope')->assertNotFound();
    }

    public function test_old_addresses_redirect_to_new_pages(): void
    {
        $map = [
            '/admin/lots-browse'     => '/admin/lots',
            '/admin/changes'         => '/admin/lot-changes',
            '/admin/stats'           => '/admin/lot-changes',
            '/admin/jobs'            => '/admin/platforms/encar?tab=runs',
            '/admin/jobs/12/detail'  => '/admin/platforms/encar?tab=runs',
            '/admin/schedules'       => '/admin/platforms/encar?tab=schedule',
            '/admin/filters'         => '/admin/platforms/encar?tab=rules',
            '/admin/filter-skip-log' => '/admin/platforms/encar?tab=skips',
            '/admin/fields'          => '/admin/field-coverage',
            '/admin/bot-filters'     => '/admin/search-settings',
            '/admin/users'           => '/admin/admin-users',
            '/panel'                 => '/admin',
            '/panel/lots/123'        => '/admin/lots/123',
        ];
        foreach ($map as $from => $to) {
            $this->get($from)->assertStatus(301)->assertRedirect($to);
        }
    }
}
