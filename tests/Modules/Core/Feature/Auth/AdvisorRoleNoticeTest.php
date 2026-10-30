<?php

use App\Models\User;
use App\Modules\Core\Application\Auth\SignInWithMicrosoft;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Core\Contracts\MailSender;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\FakeMailSender;
use Tests\Modules\Core\Support\CoreUsers;
use Tests\Modules\Core\Support\FakeIdentityProvider;

/*
| The notice to IT and internal control when a sign-in makes someone "asesor"
| on its own, matched by the SAP e-mail (audit S-17).
*/

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-30 08:15:00', 'America/Bogota'));
    config([
        'services.microsoft.tenant' => FakeIdentityProvider::TENANT,
        'core.audit.alert_recipients' => ['ti@segurex.com', 'control@segurex.com'],
    ]);
    $this->app->instance(MailSender::class, $this->mail = new FakeMailSender);
});

/**
 * Signs Ana in, as the Microsoft callback does.
 */
function signInForAdvisorNotice(string $email = 'ana.gomez@segurex.com', string $objectId = 'oid-ana'): User
{
    return app(SignInWithMicrosoft::class)->handle(FakeIdentityProvider::identity($email, $objectId, name: 'Ana Gómez'));
}

/**
 * The text of an e-mail as its reader sees it, on one line.
 */
function advisorNoticeText(OutgoingMail $mail): string
{
    return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($mail->html), ENT_QUOTES | ENT_HTML5)));
}

it('mails who became advisor, of which companies and through which SAP advisors', function () {
    AdvisorRecord::factory()->create(['company' => CompanyCode::SGX, 'code' => 12, 'email' => 'ana.gomez@segurex.com']);
    AdvisorRecord::factory()->create(['company' => CompanyCode::SDC, 'code' => 5, 'email' => 'ana.gomez@segurex.com']);

    $user = signInForAdvisorNotice();

    expect($this->mail->sent())->toHaveCount(1);

    $mail = $this->mail->sent()[0];

    expect($mail->company)->toBe(CompanyCode::SGX)
        ->and($mail->to)->toBe(['ti@segurex.com', 'control@segurex.com'])
        ->and($mail->category)->toBe('alerta')
        ->and($mail->subject)->toBe('Aviso: Ana Gómez recibió el rol de asesor al ingresar al gestor de cartera')
        ->and(advisorNoticeText($mail))
        ->toContain('Usuario: Ana Gómez (ana.gomez@segurex.com)')
        ->toContain('Cuándo: 30/10/2026 08:15')
        ->toContain('Empresas: Segurex Latam, Soluciones y Derivados')
        ->toContain('Asesores de SAP: Soluciones y Derivados 5, Segurex Latam 12')
        ->and($mail->html)->toContain(route('core.equipo.edit', ['user' => $user->id]));
});

it('mails nothing when the sign-in gives no role', function () {
    $user = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX);
    $user->update(['email' => 'ana.gomez@segurex.com', 'microsoft_id' => 'oid-ana']);
    AdvisorRecord::factory()->create(['company' => CompanyCode::SDC, 'email' => 'ana.gomez@segurex.com']);
    AdvisorRecord::factory()->create(['company' => CompanyCode::SGX, 'email' => 'pedro.ruiz@gmail.com']);

    signInForAdvisorNotice();
    signInForAdvisorNotice('pedro.ruiz@gmail.com', 'oid-pedro');

    expect($this->mail->sent())->toBe([]);
});

it('only logs when no recipients are configured, and the role stays', function () {
    config(['core.audit.alert_recipients' => []]);
    AdvisorRecord::factory()->create(['company' => CompanyCode::SGX, 'email' => 'ana.gomez@segurex.com']);
    Log::spy();

    $user = signInForAdvisorNotice();

    expect($this->mail->sent())->toBe([])
        ->and($user->fresh()?->getRoleNames()->all())->toBe([Role::SalesAdvisor->value]);
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'no notice recipients') && $context === ['user_id' => $user->id],
    )->once();
});

it('logs when Graph does not take the notice, and the role stays', function () {
    $this->mail->failNextWith(new MailNotSent('Graph no respondió.'));
    AdvisorRecord::factory()->create(['company' => CompanyCode::SGX, 'email' => 'ana.gomez@segurex.com']);
    Log::spy();

    $user = signInForAdvisorNotice();

    expect($user->fresh()?->getRoleNames()->all())->toBe([Role::SalesAdvisor->value]);
    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'could not be sent') && $context['user_id'] === $user->id,
    )->once();
});

it('logs when no module implements MailSender, and the role stays', function () {
    $this->app->offsetUnset(MailSender::class);
    AdvisorRecord::factory()->create(['company' => CompanyCode::SGX, 'email' => 'ana.gomez@segurex.com']);
    Log::spy();

    $user = signInForAdvisorNotice();

    expect($user->fresh()?->getRoleNames()->all())->toBe([Role::SalesAdvisor->value]);
    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'MailSender has no implementation') && $context === ['user_id' => $user->id],
    )->once();
});
