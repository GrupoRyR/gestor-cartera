<?php

use App\Models\User;
use App\Modules\Bancos\Database\Seeders\BancosPermissionSeeder;
use App\Modules\Bancos\Domain\Movement\ImportSource;
use App\Modules\Bancos\Infrastructure\Persistence\BankLoad;
use App\Modules\Bancos\Infrastructure\Persistence\BankMovement;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\FakeCurrentCompany;
use Tests\Modules\Bancos\Support\BancosUsers;
use Tests\Modules\Bancos\Support\Fixtures;

beforeEach(function () {
    $this->seed(BancosPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
});

/**
 * Posts the upload form with the invented statement of $file (the Bogotá one
 * by default); the fields can be overridden.
 *
 * @param  array<string, mixed>  $fields
 */
function bancosUpload(User $user, array $fields = [], ?UploadedFile $file = null): TestResponse
{
    $file ??= new UploadedFile(Fixtures::path(Bank::Bogota), 'extracto.csv', 'text/csv', null, true);

    return test()->actingAs($user)->post('/bancos/cargas', array_merge([
        'empresa' => 'SGX',
        'banco' => 'bogota',
        'archivo' => $file,
    ], $fields));
}

it('serves the loads under /bancos/cargas', function () {
    expect(route('bancos.cargas.index', absolute: false))->toBe('/bancos/cargas')
        ->and(route('bancos.cargas.store', absolute: false))->toBe('/bancos/cargas')
        ->and(route('bancos.cargas.show', 5, absolute: false))->toBe('/bancos/cargas/5');
});

describe('access', function () {
    it('sends a guest to the login', function () {
        $this->get('/bancos/cargas')->assertRedirect('/login');
        $this->post('/bancos/cargas')->assertRedirect('/login');
    });

    it('lets the portfolio team and Treasury see the history', function (Role $role) {
        $this->actingAs(BancosUsers::create($role))->get('/bancos/cargas')->assertOk();
    })->with(BancosUsers::viewers());

    it('keeps everyone else out', function (?Role $role) {
        $user = BancosUsers::create($role);

        $this->actingAs($user)->get('/bancos/cargas')->assertForbidden();
        bancosUpload($user)->assertForbidden();
        expect(BankMovement::query()->count())->toBe(0);
    })->with(BancosUsers::outsiders());
});

describe('uploading a statement', function () {
    it('stores the credits and says how many are new', function () {
        $user = BancosUsers::create(Role::PortfolioAnalyst);

        bancosUpload($user)
            ->assertRedirect('/bancos/cargas')
            ->assertSessionHas('status', '4 créditos nuevos de Banco de Bogotá (SGX); 0 ya estaban cargados.');

        $load = BankLoad::query()->sole();

        expect(BankMovement::query()->count())->toBe(4)
            ->and($load->source)->toBe(ImportSource::App)
            ->and($load->file_name)->toBe('extracto.csv')
            ->and($load->user_id)->toBe($user->id)
            ->and($load->company)->toBe(CompanyCode::SGX);
    });

    it('does not duplicate anything when the same statement is uploaded again', function () {
        $user = BancosUsers::create(Role::PortfolioAnalyst);

        bancosUpload($user);
        bancosUpload($user)->assertSessionHas('status', '0 créditos nuevos de Banco de Bogotá (SGX); 4 ya estaban cargados.');

        expect(BankMovement::query()->count())->toBe(4);
    });

    it('lets Treasury upload', function () {
        bancosUpload(BancosUsers::create(Role::Treasury))->assertRedirect('/bancos/cargas');

        expect(BankMovement::query()->count())->toBe(4);
    });

    it('reads an .xlsx as well', function () {
        $path = Fixtures::workbook([['Fecha', 'Descripción', 'Referencia', 'Débito', 'Crédito'], ['15/10/2026', 'Pago', 'R1', '', '1.000,00']]);

        try {
            bancosUpload(BancosUsers::create(Role::PortfolioAnalyst), file: new UploadedFile($path, 'extracto.xlsx', null, null, true))
                ->assertRedirect('/bancos/cargas');
        } finally {
            unlink($path);
        }

        expect(BankMovement::query()->sole()->amount)->toBe('1000.00');
    });

    it('tells the user when the file is from another bank, and keeps what they chose', function () {
        bancosUpload(BancosUsers::create(Role::PortfolioAnalyst), ['banco' => 'davivienda'])
            ->assertRedirect('/bancos/cargas')
            ->assertSessionHasErrors(['archivo'])
            ->assertSessionHasInput('banco', 'davivienda');

        expect(BankMovement::query()->count())->toBe(0)
            ->and(session('errors')->first('archivo'))->toContain('Davivienda');
    });

    it('explains what to do with an .xls', function () {
        $file = UploadedFile::fake()->create('extracto.xls', 10);

        bancosUpload(BancosUsers::create(Role::PortfolioAnalyst), file: $file)->assertSessionHasErrors(['archivo']);

        expect(session('errors')->first('archivo'))->toContain('.xlsx');
    });

    it('requires the three fields', function () {
        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))
            ->post('/bancos/cargas', [])
            ->assertSessionHasErrors(['empresa', 'banco', 'archivo']);
    });

    it('rejects a bank that does not exist', function () {
        bancosUpload(BancosUsers::create(Role::PortfolioAnalyst), ['banco' => 'bbva'])->assertSessionHasErrors(['banco']);
    });

    it('rejects a company the user cannot choose', function () {
        $this->app->instance(CurrentCompany::class, new FakeCurrentCompany(CompanyCode::SGX, [CompanyCode::SGX]));

        bancosUpload(BancosUsers::create(Role::PortfolioAnalyst), ['empresa' => 'SDC'])->assertSessionHasErrors(['empresa']);

        expect(BankMovement::query()->count())->toBe(0);
    });

    it('sends the user to the detail when some rows could not be read', function () {
        $csv = "Fecha;Descripción;Referencia;Débito;Crédito\n15/10/2026;Bueno;R1;;1.000,00\n15/10/2026;Malo;R2;;1000.55\n";
        $file = UploadedFile::fake()->createWithContent('extracto.csv', $csv);

        $response = bancosUpload(BancosUsers::create(Role::PortfolioAnalyst), file: $file);
        $load = BankLoad::query()->sole();

        $response->assertRedirect('/bancos/cargas/'.$load->id)->assertSessionHas('error');
        expect($load->error_count)->toBe(1);
    });
});

describe('the history', function () {
    it('lists the loads of the company in view, newest first', function () {
        $user = BancosUsers::create(Role::PortfolioAnalyst);
        BankLoad::query()->create(['company' => CompanyCode::SGX, 'bank' => Bank::Bogota, 'source' => ImportSource::Folder, 'file_name' => 'viejo-sgx.csv']);
        BankLoad::query()->create(['company' => CompanyCode::SDC, 'bank' => Bank::Davivienda, 'source' => ImportSource::Api, 'file_name' => 'sdc.csv']);
        BankLoad::query()->create(['company' => CompanyCode::SGX, 'bank' => Bank::Miami, 'source' => ImportSource::App, 'file_name' => 'nuevo-sgx.csv']);

        $this->actingAs($user)->get('/bancos/cargas')
            ->assertOk()
            ->assertSeeInOrder(['nuevo-sgx.csv', 'viejo-sgx.csv'])
            ->assertDontSee('sdc.csv');

        $this->fakes->currentCompany->switchTo(null);

        $this->actingAs($user)->get('/bancos/cargas')->assertSee('sdc.csv');
    });

    it('marks a failed load and one with errors', function () {
        BankLoad::query()->create(['company' => CompanyCode::SGX, 'bank' => Bank::Bogota, 'source' => ImportSource::Folder, 'failed' => true, 'error_count' => 1, 'errors' => [['row' => null, 'message' => 'x']]]);
        BankLoad::query()->create(['company' => CompanyCode::SGX, 'bank' => Bank::Bogota, 'source' => ImportSource::Folder, 'error_count' => 2]);
        BankLoad::query()->create(['company' => CompanyCode::SGX, 'bank' => Bank::Bogota, 'source' => ImportSource::Folder]);

        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->get('/bancos/cargas')
            ->assertSee('No se pudo cargar')
            ->assertSee('Con filas con error')
            ->assertSee('Cargado');
    });

    it('invites the user to upload when there is nothing yet', function () {
        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->get('/bancos/cargas')->assertSee('Aún no hay extractos cargados');
    });

    it('shows the upload form only to those who may upload', function () {
        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->get('/bancos/cargas')->assertSee('Subir un extracto');

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('bancos.view');

        $this->actingAs($viewer)->get('/bancos/cargas')->assertOk()->assertDontSee('Subir un extracto');
    });

    it('shows the rows that failed in the detail of a load', function () {
        $load = BankLoad::query()->create([
            'company' => CompanyCode::SGX, 'bank' => Bank::Bogota, 'source' => ImportSource::App, 'file_name' => 'extracto.csv',
            'error_count' => 1, 'errors' => [['row' => 7, 'message' => 'La fecha «31/02/2026» no es válida.']],
        ]);

        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->get('/bancos/cargas/'.$load->id)
            ->assertOk()
            ->assertSee('31/02/2026')
            ->assertSee('extracto.csv');
    });

    it('answers 404 for the detail of a load of a company the user cannot see', function () {
        $this->app->instance(CurrentCompany::class, new FakeCurrentCompany(CompanyCode::SGX, [CompanyCode::SGX]));
        $load = BankLoad::query()->create(['company' => CompanyCode::SDC, 'bank' => Bank::Bogota, 'source' => ImportSource::App]);

        $this->actingAs(BancosUsers::create(Role::PortfolioAnalyst))->get('/bancos/cargas/'.$load->id)->assertNotFound();
    });
});
