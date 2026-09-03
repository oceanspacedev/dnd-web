<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Divisi;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Services\UserJsonImportService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserJsonImportServiceTest extends TestCase
{
    private Area $defaultArea;

    private Divisi $defaultDivisi;

    private Role $defaultRole;

    private Position $defaultPosition;

    private int $userSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearEloquentGuardableColumns();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge('sqlite');
        DB::setDefaultConnection('sqlite');

        $this->createSchema();

        $this->defaultArea = Area::create(['name' => 'HEAD OFFICE']);
        $this->defaultDivisi = Divisi::create([
            'area_id' => $this->defaultArea->id,
            'name' => 'GENERAL',
        ]);
        $this->defaultRole = Role::create(['name' => 'STAFF']);
        $this->defaultPosition = Position::create(['name' => 'GENERAL STAFF']);
    }

    protected function tearDown(): void
    {
        $this->clearEloquentGuardableColumns();

        parent::tearDown();
    }

    public function test_existing_users_are_matched_by_employee_id_explicit_username_or_unique_legacy_name(): void
    {
        $byEmployeeId = $this->createUser([
            'employee_id' => 'EMP-001',
            'nama_lengkap' => 'Employee Id Target',
            'username' => 'employee-id-target',
            'email' => 'old-id@example.test',
            'no_hp' => '081111111111',
        ]);
        $byUsername = $this->createUser([
            'employee_id' => 'EMP-002',
            'nama_lengkap' => 'Username Target',
            'username' => 'username-target',
            'email' => 'old-username@example.test',
            'no_hp' => '082222222222',
        ]);
        $byName = $this->createUser([
            'employee_id' => null,
            'nama_lengkap' => 'Nama Target',
            'username' => 'name-target',
            'email' => 'old-name@example.test',
            'no_hp' => '083333333333',
        ]);

        $result = $this->importRows([
            [
                'employee_id' => ' EMP-001 ',
                'full_name' => 'Nama Berbeda Dari Database',
                'email' => ' Id.Target@Example.TEST ',
            ],
            [
                'employee_id' => 'HR-ID-DOES-NOT-MATCH',
                'username' => ' USERNAME-TARGET ',
                'full_name' => 'Nama Juga Berbeda',
                'phone' => '+62 812-3456-7890',
            ],
            [
                'employee_id' => 'HR-ID-BARU',
                'username' => 'unknown-explicit-username',
                'full_name' => '  nAmA    tArGeT  ',
                'email_address' => ' Name.Target@Example.Test ',
                'mobile_phone' => '0062 813 4567 8901',
            ],
        ]);

        $this->assertImportCounts($result, 3, 0);
        $this->assertSame(3, User::query()->count());

        $byEmployeeId->refresh();
        $this->assertSame('id.target@example.test', $byEmployeeId->email);
        $this->assertSame('081111111111', $byEmployeeId->no_hp);
        $this->assertSame('EMP-001', $byEmployeeId->employee_id);
        $this->assertSame('Employee Id Target', $byEmployeeId->nama_lengkap);

        $byUsername->refresh();
        $this->assertSame('old-username@example.test', $byUsername->email);
        $this->assertSame('081234567890', $byUsername->no_hp);
        $this->assertSame('EMP-002', $byUsername->employee_id);
        $this->assertSame('Username Target', $byUsername->nama_lengkap);

        $byName->refresh();
        $this->assertSame('name.target@example.test', $byName->email);
        $this->assertSame('081345678901', $byName->no_hp);
        $this->assertNull($byName->employee_id);
        $this->assertSame('name-target', $byName->username);
        $this->assertSame('Nama Target', $byName->nama_lengkap);
    }

    public function test_conflicting_identifiers_and_ambiguous_normalized_name_are_rejected(): void
    {
        $first = $this->createUser([
            'employee_id' => 'EMP-A',
            'nama_lengkap' => 'First Identity',
            'username' => 'first-identity',
            'email' => 'first@example.test',
        ]);
        $second = $this->createUser([
            'employee_id' => 'EMP-B',
            'nama_lengkap' => 'Second Identity',
            'username' => 'second-identity',
            'email' => 'second@example.test',
        ]);
        $ambiguousOne = $this->createUser([
            'employee_id' => null,
            'nama_lengkap' => 'Nama Kembar',
            'username' => 'nama-kembar-1',
            'no_hp' => '081111111111',
        ]);
        $ambiguousTwo = $this->createUser([
            'employee_id' => null,
            'nama_lengkap' => '  NAMA   KEMBAR ',
            'username' => 'nama-kembar-2',
            'no_hp' => '082222222222',
        ]);

        $result = $this->importRows([
            [
                'employee_id' => 'EMP-A',
                'username' => 'second-identity',
                'full_name' => 'Conflicting Identifiers',
                'email' => 'must-not-change@example.test',
            ],
            [
                'employee_id' => 'UNMATCHED-ID',
                'full_name' => ' nama    kembar ',
                'no_hp' => '+62 899 9999 9999',
            ],
        ]);

        $this->assertImportCounts($result, 0, 2);
        $this->assertSame(4, User::query()->count());
        $this->assertSame('first@example.test', $first->fresh()->email);
        $this->assertSame('second@example.test', $second->fresh()->email);
        $this->assertSame('081111111111', $ambiguousOne->fresh()->no_hp);
        $this->assertSame('082222222222', $ambiguousTwo->fresh()->no_hp);
    }

    public function test_existing_sync_only_changes_explicit_contacts_and_preserves_every_dnd_field(): void
    {
        $approval = $this->createUser([
            'employee_id' => 'APPROVER-001',
            'nama_lengkap' => 'Original Approver',
            'username' => 'original-approver',
        ]);
        $originalPassword = Hash::make('original-secret');
        $user = $this->createUser([
            'employee_id' => 'KEEP-001',
            'nama_lengkap' => 'Keep Dnd Configuration',
            'username' => 'keep-dnd-configuration',
            'email' => 'old@example.test',
            'no_hp' => '081111111111',
            'password' => $originalPassword,
            'approval_id' => $approval->id,
            'd' => false,
            'dr' => true,
            'wn' => false,
            'wr' => true,
            'mn' => true,
            'mr' => false,
            'profile_picture' => 'profiles/original.png',
            'id_notif' => 'original-device-token',
        ])->refresh();

        $protectedBefore = $this->protectedUserAttributes($user);
        $masterCountsBefore = $this->masterCounts();

        $result = $this->importRows([
            [
                'employee_id' => 'KEEP-001',
                'username' => 'replacement-username',
                'full_name' => 'Replacement Name',
                'email' => ' Updated@Example.COM ',
                'no_hp' => '+62 (812) 3456-7890',
                'area' => 'NEW AREA MUST NOT EXIST',
                'divisi' => 'NEW DIVISI MUST NOT EXIST',
                'position' => 'NEW POSITION MUST NOT EXIST',
                'role' => 'ADMIN',
                'approval' => 'replacement-approver',
                'password' => 'replacement-password',
                'd' => true,
                'dr' => false,
                'wn' => true,
                'wr' => false,
                'mn' => false,
                'mr' => true,
                'profile_picture' => 'profiles/replacement.png',
                'id_notif' => 'replacement-device-token',
                'deleted_at' => '2020-01-01 00:00:00',
            ],
        ]);

        $this->assertImportCounts($result, 1, 0);
        $user->refresh();
        $this->assertSame('updated@example.com', $user->email);
        $this->assertSame('081234567890', $user->no_hp);
        $this->assertSame($protectedBefore, $this->protectedUserAttributes($user));
        $this->assertSame($masterCountsBefore, $this->masterCounts());

        $omittedContacts = $this->importRows([
            [
                'employee_id' => 'KEEP-001',
                'full_name' => 'This Name Is Ignored',
            ],
        ]);

        $this->assertImportCounts($omittedContacts, 1, 0);
        $user->refresh();
        $this->assertSame('updated@example.com', $user->email);
        $this->assertSame('081234567890', $user->no_hp);
        $this->assertSame($protectedBefore, $this->protectedUserAttributes($user));

        $blankContacts = $this->importRows([
            [
                'employee_id' => 'KEEP-001',
                'email' => '   ',
                'no_hp' => null,
            ],
        ]);

        $this->assertImportCounts($blankContacts, 1, 0);
        $user->refresh();
        $this->assertNull($user->email);
        $this->assertNull($user->no_hp);
        $this->assertSame($protectedBefore, $this->protectedUserAttributes($user));
        $this->assertSame($masterCountsBefore, $this->masterCounts());
    }

    public function test_invalid_contacts_reject_rows_without_partial_updates(): void
    {
        $invalidPhoneUser = $this->createUser([
            'employee_id' => 'INVALID-PHONE',
            'nama_lengkap' => 'Invalid Phone',
            'username' => 'invalid-phone',
            'email' => 'old-phone@example.test',
            'no_hp' => '081111111111',
        ]);
        $invalidEmailUser = $this->createUser([
            'employee_id' => 'INVALID-EMAIL',
            'nama_lengkap' => 'Invalid Email',
            'username' => 'invalid-email',
            'email' => 'old-email@example.test',
            'no_hp' => '082222222222',
        ]);

        $result = $this->importRows([
            [
                'employee_id' => 'INVALID-PHONE',
                'email' => 'valid-but-must-not-be-saved@example.test',
                'no_hp' => '0812ABC',
            ],
            [
                'employee_id' => 'INVALID-EMAIL',
                'email' => 'not-an-email',
                'no_hp' => '+62 813-4567-8901',
            ],
        ]);

        $this->assertImportCounts($result, 0, 2);
        $invalidPhoneUser->refresh();
        $this->assertSame('old-phone@example.test', $invalidPhoneUser->email);
        $this->assertSame('081111111111', $invalidPhoneUser->no_hp);
        $invalidEmailUser->refresh();
        $this->assertSame('old-email@example.test', $invalidEmailUser->email);
        $this->assertSame('082222222222', $invalidEmailUser->no_hp);
    }

    public function test_deleted_users_are_not_restored_updated_or_replaced(): void
    {
        $deleted = $this->createUser([
            'employee_id' => 'DELETED-001',
            'nama_lengkap' => 'Deleted Employee',
            'username' => 'deleted-employee',
            'email' => 'deleted@example.test',
            'no_hp' => '081111111111',
        ]);
        $deleted->delete();
        $deletedAt = User::withTrashed()->findOrFail($deleted->id)->deleted_at;

        $result = $this->importRows([
            [
                'employee_id' => 'DELETED-001',
                'username' => 'deleted-employee',
                'full_name' => 'Deleted Employee',
                'email' => 'must-not-update@example.test',
                'no_hp' => '+62 812-3456-7890',
            ],
        ]);

        $this->assertImportCounts($result, 0, 1);
        $this->assertSame(0, User::query()->count());
        $this->assertSame(1, User::withTrashed()->count());

        $deleted = User::withTrashed()->findOrFail($deleted->id);
        $this->assertTrue($deleted->trashed());
        $this->assertTrue($deletedAt->equalTo($deleted->deleted_at));
        $this->assertSame('deleted@example.test', $deleted->email);
        $this->assertSame('081111111111', $deleted->no_hp);
    }

    public function test_new_user_clones_a_unanimous_active_non_admin_template_and_ignores_json_privileges(): void
    {
        $targetArea = Area::create(['name' => 'JAKARTA']);
        $otherArea = Area::create(['name' => 'BANDUNG']);
        $targetDivisi = Divisi::create(['area_id' => $targetArea->id, 'name' => 'OPERATIONS']);
        $otherDivisi = Divisi::create(['area_id' => $targetArea->id, 'name' => 'FINANCE']);
        $otherAreaDivisi = Divisi::create(['area_id' => $otherArea->id, 'name' => 'OPERATIONS']);
        $coordinatorRole = Role::create(['name' => 'COORDINATOR']);
        $adminRole = Role::create(['name' => 'ADMIN']);
        $targetPosition = Position::create(['name' => 'FIELD ANALYST']);
        $otherPosition = Position::create(['name' => 'OFFICE ANALYST']);
        $approval = $this->createUser([
            'employee_id' => 'APPROVER-NEW',
            'nama_lengkap' => 'Template Approver',
            'username' => 'template-approver',
        ]);

        $templateConfiguration = [
            'role_id' => $coordinatorRole->id,
            'area_id' => $targetArea->id,
            'divisi_id' => $targetDivisi->id,
            'position_id' => $targetPosition->id,
            'approval_id' => $approval->id,
            'd' => true,
            'dr' => false,
            'wn' => true,
            'wr' => true,
            'mn' => false,
            'mr' => true,
        ];
        $firstTemplate = $this->createUser(array_merge($templateConfiguration, [
            'employee_id' => 'TEMPLATE-001',
            'nama_lengkap' => 'First Template',
            'username' => 'first-template',
            'email' => 'first-template@example.test',
            'no_hp' => '081111111111',
            'password' => Hash::make('first-template-secret'),
            'profile_picture' => 'profiles/template.png',
            'id_notif' => 'template-device-token',
        ]));
        $this->createUser(array_merge($templateConfiguration, [
            'employee_id' => 'TEMPLATE-002',
            'nama_lengkap' => 'Second Template',
            'username' => 'second-template',
            'email' => 'second-template@example.test',
            'password' => Hash::make('different-template-secret'),
        ]));

        $this->createUser([
            'employee_id' => 'EXCLUDED-ADMIN',
            'nama_lengkap' => 'Excluded Admin',
            'username' => 'excluded-admin',
            'role_id' => $adminRole->id,
            'area_id' => $targetArea->id,
            'divisi_id' => $targetDivisi->id,
            'position_id' => $targetPosition->id,
            'd' => false,
            'dr' => true,
            'wn' => false,
            'wr' => false,
            'mn' => true,
            'mr' => false,
        ]);
        $deletedTemplate = $this->createUser([
            'employee_id' => 'EXCLUDED-DELETED',
            'nama_lengkap' => 'Excluded Deleted Template',
            'username' => 'excluded-deleted-template',
            'role_id' => $coordinatorRole->id,
            'area_id' => $targetArea->id,
            'divisi_id' => $targetDivisi->id,
            'position_id' => $targetPosition->id,
            'approval_id' => null,
            'd' => false,
            'dr' => true,
            'wn' => false,
            'wr' => false,
            'mn' => true,
            'mr' => false,
        ]);
        $deletedTemplate->delete();
        $this->createUser(array_merge($templateConfiguration, [
            'employee_id' => 'DECOY-DIVISI',
            'nama_lengkap' => 'Wrong Divisi',
            'username' => 'wrong-divisi',
            'divisi_id' => $otherDivisi->id,
        ]));
        $this->createUser(array_merge($templateConfiguration, [
            'employee_id' => 'DECOY-AREA',
            'nama_lengkap' => 'Wrong Area',
            'username' => 'wrong-area',
            'area_id' => $otherArea->id,
            'divisi_id' => $otherAreaDivisi->id,
        ]));
        $this->createUser(array_merge($templateConfiguration, [
            'employee_id' => 'DECOY-POSITION',
            'nama_lengkap' => 'Wrong Position',
            'username' => 'wrong-position',
            'position_id' => $otherPosition->id,
        ]));
        $sameNameNonLegacy = $this->createUser([
            'employee_id' => 'EXISTING-SAME-NAME',
            'nama_lengkap' => 'New Employee',
            'username' => 'existing-same-name',
            'email' => 'existing-same-name@example.test',
            'no_hp' => '089999999999',
        ]);

        $masterCountsBefore = $this->masterCounts();
        $initialPassword = 'New-Employee!2026';
        $result = $this->importRows([
            [
                'employee_id' => 'NEW-001',
                'full_name' => 'New Employee',
                'initial_password' => $initialPassword,
                'email' => ' New.Employee@Example.COM ',
                'phone' => '+62 812-3456-7890',
                'position' => '  field   analyst ',
                'area' => ' jakarta ',
                'divisi' => ' operations ',
                'role' => 'ADMIN',
                'approval_id' => null,
                'd' => false,
                'dr' => true,
                'wn' => false,
                'wr' => false,
                'mn' => true,
                'mr' => false,
                'profile_picture' => 'profiles/from-json.png',
                'id_notif' => 'json-device-token',
            ],
        ]);

        $this->assertImportCounts($result, 1, 0);
        $newUser = User::query()->where('employee_id', 'NEW-001')->firstOrFail();
        $this->assertSame('new.employee@example.com', $newUser->email);
        $this->assertSame('081234567890', $newUser->no_hp);
        $this->assertNotSame('', $newUser->username);
        $this->assertNotSame($firstTemplate->username, $newUser->username);
        $this->assertNotSame('', $newUser->password);
        $this->assertNotSame($firstTemplate->password, $newUser->password);
        $this->assertSame(1, $result['created_count'] ?? null);
        $this->assertArrayNotHasKey('credentials', $result);
        $this->assertTrue(Hash::check($initialPassword, $newUser->password));
        $this->assertFalse(Hash::check('complete123', $newUser->password));
        $this->assertSame(
            $this->templateFingerprint($firstTemplate->refresh()),
            $this->templateFingerprint($newUser),
        );
        $this->assertNull($newUser->profile_picture);
        $this->assertNull($newUser->id_notif);
        $this->assertNull($newUser->deleted_at);
        $sameNameNonLegacy->refresh();
        $this->assertSame('existing-same-name@example.test', $sameNameNonLegacy->email);
        $this->assertSame('089999999999', $sameNameNonLegacy->no_hp);
        $this->assertSame($masterCountsBefore, $this->masterCounts());
    }

    public function test_new_user_is_rejected_when_matching_templates_do_not_share_one_fingerprint(): void
    {
        $area = Area::create(['name' => 'SURABAYA']);
        $divisi = Divisi::create(['area_id' => $area->id, 'name' => 'SALES']);
        $role = Role::create(['name' => 'SALES STAFF']);
        $otherRole = Role::create(['name' => 'SALES COORDINATOR']);
        $approvalOne = $this->createUser([
            'employee_id' => 'APPROVAL-ONE',
            'nama_lengkap' => 'Approval One',
            'username' => 'approval-one',
        ]);
        $approvalTwo = $this->createUser([
            'employee_id' => 'APPROVAL-TWO',
            'nama_lengkap' => 'Approval Two',
            'username' => 'approval-two',
        ]);
        $approvalConflictPosition = Position::create(['name' => 'ACCOUNT EXECUTIVE']);
        $flagConflictPosition = Position::create(['name' => 'SALES SUPPORT']);
        $roleConflictPosition = Position::create(['name' => 'BUSINESS DEVELOPMENT']);

        $base = [
            'role_id' => $role->id,
            'area_id' => $area->id,
            'divisi_id' => $divisi->id,
            'd' => true,
            'dr' => false,
            'wn' => true,
            'wr' => false,
            'mn' => false,
            'mr' => false,
        ];

        $this->createUser(array_merge($base, [
            'employee_id' => 'APPROVAL-TEMPLATE-1',
            'nama_lengkap' => 'Approval Template One',
            'username' => 'approval-template-1',
            'position_id' => $approvalConflictPosition->id,
            'approval_id' => $approvalOne->id,
        ]));
        $this->createUser(array_merge($base, [
            'employee_id' => 'APPROVAL-TEMPLATE-2',
            'nama_lengkap' => 'Approval Template Two',
            'username' => 'approval-template-2',
            'position_id' => $approvalConflictPosition->id,
            'approval_id' => $approvalTwo->id,
        ]));
        $this->createUser(array_merge($base, [
            'employee_id' => 'FLAG-TEMPLATE-1',
            'nama_lengkap' => 'Flag Template One',
            'username' => 'flag-template-1',
            'position_id' => $flagConflictPosition->id,
            'approval_id' => $approvalOne->id,
            'wr' => false,
        ]));
        $this->createUser(array_merge($base, [
            'employee_id' => 'FLAG-TEMPLATE-2',
            'nama_lengkap' => 'Flag Template Two',
            'username' => 'flag-template-2',
            'position_id' => $flagConflictPosition->id,
            'approval_id' => $approvalOne->id,
            'wr' => true,
        ]));
        $this->createUser(array_merge($base, [
            'employee_id' => 'ROLE-TEMPLATE-1',
            'nama_lengkap' => 'Role Template One',
            'username' => 'role-template-1',
            'position_id' => $roleConflictPosition->id,
            'approval_id' => $approvalOne->id,
        ]));
        $this->createUser(array_merge($base, [
            'employee_id' => 'ROLE-TEMPLATE-2',
            'nama_lengkap' => 'Role Template Two',
            'username' => 'role-template-2',
            'role_id' => $otherRole->id,
            'position_id' => $roleConflictPosition->id,
            'approval_id' => $approvalOne->id,
        ]));

        $userCountBefore = User::withTrashed()->count();
        $result = $this->importRows([
            [
                'employee_id' => 'AMBIGUOUS-APPROVAL',
                'full_name' => 'Ambiguous Approval',
                'position' => 'account executive',
                'area' => 'surabaya',
                'divisi' => 'sales',
                'initial_password' => 'Ambiguous-Approval!2026',
            ],
            [
                'employee_id' => 'AMBIGUOUS-FLAG',
                'full_name' => 'Ambiguous Flag',
                'position' => 'sales support',
                'area' => 'surabaya',
                'divisi' => 'sales',
                'initial_password' => 'Ambiguous-Flag!2026',
            ],
            [
                'employee_id' => 'AMBIGUOUS-ROLE',
                'full_name' => 'Ambiguous Role',
                'position' => 'business development',
                'area' => 'surabaya',
                'divisi' => 'sales',
                'initial_password' => 'Ambiguous-Role!2026',
            ],
        ]);

        $this->assertImportCounts($result, 0, 3);
        $this->assertSame($userCountBefore, User::withTrashed()->count());
        $this->assertFalse(User::withTrashed()->whereIn('employee_id', [
            'AMBIGUOUS-APPROVAL',
            'AMBIGUOUS-FLAG',
            'AMBIGUOUS-ROLE',
        ])->exists());
    }

    public function test_new_user_requires_employee_id_name_and_a_matching_template(): void
    {
        $position = Position::create(['name' => 'KNOWN POSITION']);
        $this->createUser([
            'employee_id' => 'KNOWN-TEMPLATE',
            'nama_lengkap' => 'Known Template',
            'username' => 'known-template',
            'position_id' => $position->id,
        ]);

        $userCountBefore = User::withTrashed()->count();
        $masterCountsBefore = $this->masterCounts();
        $result = $this->importRows([
            [
                'full_name' => 'Missing Employee Id',
                'position' => 'KNOWN POSITION',
                'initial_password' => 'Missing-Employee!2026',
            ],
            [
                'employee_id' => 'MISSING-NAME',
                'position' => 'KNOWN POSITION',
                'initial_password' => 'Missing-Name!2026',
            ],
            [
                'employee_id' => 'NO-TEMPLATE',
                'full_name' => 'No Matching Template',
                'position' => 'POSITION THAT DOES NOT EXIST',
                'area' => 'AREA THAT DOES NOT EXIST',
                'divisi' => 'DIVISI THAT DOES NOT EXIST',
                'initial_password' => 'Missing-Template!2026',
            ],
            [
                'employee_id' => 'MISSING-PASSWORD',
                'full_name' => 'Missing Password',
                'position' => 'KNOWN POSITION',
            ],
        ]);

        $this->assertImportCounts($result, 0, 4);
        $this->assertSame($userCountBefore, User::withTrashed()->count());
        $this->assertSame($masterCountsBefore, $this->masterCounts());
    }

    public function test_new_rows_require_unique_passwords_while_existing_updates_ignore_password_input(): void
    {
        $existingUser = $this->createUser([
            'employee_id' => 'EXISTING-CONTACT',
            'nama_lengkap' => 'Existing Contact',
            'username' => 'existing-contact',
            'email' => 'old-contact@example.test',
            'no_hp' => '081111111111',
        ]);
        $existingPasswordHash = $existingUser->password;
        $position = Position::create(['name' => 'IMPORTED PASSWORD POSITION']);
        $this->createUser([
            'employee_id' => 'IMPORTED-PASSWORD-TEMPLATE',
            'nama_lengkap' => 'Imported Password Template',
            'username' => 'imported-password-template',
            'position_id' => $position->id,
        ]);
        $firstPassword = 'First-Imported!2026';
        $secondPassword = 'Second-Imported!2026';
        $passwordBeyondBcryptLimit = str_repeat('A', 72).'X';

        $result = $this->importRows([
            [
                'employee_id' => 'EXISTING-CONTACT',
                'email' => ' Updated.Contact@Example.TEST ',
                'no_hp' => '+62 812-3456-7890',
                'password' => $firstPassword,
            ],
            [
                'employee_id' => 'NEW-IMPORTED-PASSWORD-1',
                'full_name' => 'First Imported Password',
                'position' => 'IMPORTED PASSWORD POSITION',
                'initial_password' => $firstPassword,
            ],
            [
                'employee_id' => 'NEW-IMPORTED-PASSWORD-2',
                'full_name' => 'Second Imported Password',
                'position' => 'IMPORTED PASSWORD POSITION',
                'password' => $secondPassword,
            ],
            [
                'employee_id' => 'NEW-DUPLICATE-PASSWORD',
                'full_name' => 'Duplicate Imported Password',
                'position' => 'IMPORTED PASSWORD POSITION',
                'password' => $firstPassword,
            ],
            [
                'employee_id' => 'NEW-TOO-LONG-PASSWORD',
                'full_name' => 'Too Long Imported Password',
                'position' => 'IMPORTED PASSWORD POSITION',
                'password' => $passwordBeyondBcryptLimit,
            ],
        ]);

        $this->assertImportCounts($result, 3, 2);
        $existingUser->refresh();
        $this->assertSame('updated.contact@example.test', $existingUser->email);
        $this->assertSame('081234567890', $existingUser->no_hp);
        $this->assertSame($existingPasswordHash, $existingUser->password);
        $this->assertSame(2, $result['created_count'] ?? null);
        $this->assertArrayNotHasKey('credentials', $result);

        $firstUser = User::query()->where('employee_id', 'NEW-IMPORTED-PASSWORD-1')->firstOrFail();
        $secondUser = User::query()->where('employee_id', 'NEW-IMPORTED-PASSWORD-2')->firstOrFail();
        $this->assertTrue(Hash::check($firstPassword, $firstUser->password));
        $this->assertTrue(Hash::check($secondPassword, $secondUser->password));
        $this->assertFalse(
            User::withTrashed()->where('employee_id', 'NEW-DUPLICATE-PASSWORD')->exists(),
        );
        $this->assertFalse(
            User::withTrashed()->where('employee_id', 'NEW-TOO-LONG-PASSWORD')->exists(),
        );
    }

    public function test_master_matching_collapses_repeated_internal_whitespace_in_database_and_json_names(): void
    {
        $area = Area::create(['name' => 'NORTH   REGION']);
        $divisi = Divisi::create([
            'area_id' => $area->id,
            'name' => 'FIELD    OPERATIONS',
        ]);
        $position = Position::create(['name' => 'SENIOR   FIELD   OFFICER']);
        $template = $this->createUser([
            'employee_id' => 'WHITESPACE-TEMPLATE',
            'nama_lengkap' => 'Whitespace Template',
            'username' => 'whitespace-template',
            'area_id' => $area->id,
            'divisi_id' => $divisi->id,
            'position_id' => $position->id,
            'd' => false,
            'dr' => true,
            'wn' => true,
            'wr' => false,
            'mn' => true,
            'mr' => true,
        ]);

        $initialPassword = 'Whitespace-Match!2026';
        $result = $this->importRows([
            [
                'employee_id' => 'WHITESPACE-NEW',
                'full_name' => 'Whitespace New User',
                'area' => '  north      region  ',
                'divisi' => ' field  operations ',
                'position' => ' senior     field officer ',
                'password' => $initialPassword,
            ],
        ]);

        $this->assertImportCounts($result, 1, 0);
        $newUser = User::query()->where('employee_id', 'WHITESPACE-NEW')->firstOrFail();
        $this->assertSame($this->templateFingerprint($template->refresh()), $this->templateFingerprint($newUser));
        $this->assertSame(1, $result['created_count'] ?? null);
        $this->assertArrayNotHasKey('credentials', $result);
        $this->assertTrue(Hash::check($initialPassword, $newUser->password));
    }

    public function test_unanimous_templates_are_rejected_when_their_approver_is_soft_deleted(): void
    {
        $area = Area::create(['name' => 'APPROVAL AREA']);
        $divisi = Divisi::create(['area_id' => $area->id, 'name' => 'APPROVAL DIVISION']);
        $position = Position::create(['name' => 'APPROVAL POSITION']);
        $approver = $this->createUser([
            'employee_id' => 'DELETED-APPROVER',
            'nama_lengkap' => 'Deleted Approver',
            'username' => 'deleted-approver',
        ]);
        $approver->delete();

        $templateConfiguration = [
            'area_id' => $area->id,
            'divisi_id' => $divisi->id,
            'position_id' => $position->id,
            'approval_id' => $approver->id,
            'd' => true,
            'dr' => false,
            'wn' => true,
            'wr' => false,
            'mn' => true,
            'mr' => false,
        ];
        $this->createUser(array_merge($templateConfiguration, [
            'employee_id' => 'DELETED-APPROVER-TEMPLATE-1',
            'nama_lengkap' => 'Deleted Approver Template One',
            'username' => 'deleted-approver-template-1',
        ]));
        $this->createUser(array_merge($templateConfiguration, [
            'employee_id' => 'DELETED-APPROVER-TEMPLATE-2',
            'nama_lengkap' => 'Deleted Approver Template Two',
            'username' => 'deleted-approver-template-2',
        ]));

        $userCountBefore = User::withTrashed()->count();
        $result = $this->importRows([
            [
                'employee_id' => 'NEW-DELETED-APPROVER',
                'full_name' => 'New Deleted Approver',
                'area' => 'approval area',
                'divisi' => 'approval division',
                'position' => 'approval position',
                'initial_password' => 'Deleted-Approver!2026',
            ],
        ]);

        $this->assertImportCounts($result, 0, 1);
        $this->assertSame($userCountBefore, User::withTrashed()->count());
        $this->assertFalse(User::withTrashed()->where('employee_id', 'NEW-DELETED-APPROVER')->exists());
    }

    private function importRows(array $rows): array
    {
        return UserJsonImportService::importFromContent(
            json_encode($rows, JSON_THROW_ON_ERROR),
        );
    }

    private function clearEloquentGuardableColumns(): void
    {
        $property = new \ReflectionProperty(Model::class, 'guardableColumns');
        $property->setValue(null, []);
    }

    private function assertImportCounts(array $result, int $successCount, int $errorCount): void
    {
        $errors = implode(PHP_EOL, $result['errors'] ?? []);

        $this->assertSame($successCount, $result['success_count'] ?? null, $errors);
        $this->assertSame($errorCount, $result['error_count'] ?? null, $errors);
    }

    private function createUser(array $overrides = []): User
    {
        $this->userSequence++;

        return User::create(array_merge([
            'employee_id' => 'SEED-'.$this->userSequence,
            'nama_lengkap' => 'Seed User '.$this->userSequence,
            'username' => 'seed-user-'.$this->userSequence,
            'email' => null,
            'no_hp' => null,
            'password' => Hash::make('seed-secret-'.$this->userSequence),
            'role_id' => $this->defaultRole->id,
            'area_id' => $this->defaultArea->id,
            'divisi_id' => $this->defaultDivisi->id,
            'position_id' => $this->defaultPosition->id,
            'approval_id' => null,
            'd' => true,
            'dr' => false,
            'wn' => false,
            'wr' => false,
            'mn' => false,
            'mr' => false,
            'profile_picture' => null,
            'id_notif' => null,
        ], $overrides));
    }

    private function protectedUserAttributes(User $user): array
    {
        return $user->only([
            'employee_id',
            'nama_lengkap',
            'username',
            'password',
            'role_id',
            'area_id',
            'divisi_id',
            'position_id',
            'approval_id',
            'd',
            'dr',
            'wn',
            'wr',
            'mn',
            'mr',
            'profile_picture',
            'id_notif',
            'deleted_at',
        ]);
    }

    private function templateFingerprint(User $user): array
    {
        return $user->only([
            'role_id',
            'area_id',
            'divisi_id',
            'position_id',
            'approval_id',
            'd',
            'dr',
            'wn',
            'wr',
            'mn',
            'mr',
        ]);
    }

    private function masterCounts(): array
    {
        return [
            'areas' => Area::query()->count(),
            'divisis' => Divisi::query()->count(),
            'roles' => Role::query()->count(),
            'positions' => Position::withTrashed()->count(),
        ];
    }

    private function createSchema(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('divisis', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('area_id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id')->nullable()->unique();
            $table->string('nama_lengkap');
            $table->string('username')->unique();
            $table->string('email')->nullable();
            $table->string('no_hp')->nullable();
            $table->string('password');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('area_id');
            $table->unsignedBigInteger('divisi_id');
            $table->unsignedBigInteger('position_id')->nullable();
            $table->unsignedBigInteger('approval_id')->nullable();
            $table->boolean('d')->default(true);
            $table->boolean('dr')->default(false);
            $table->boolean('wn')->default(false);
            $table->boolean('wr')->default(false);
            $table->boolean('mn')->default(false);
            $table->boolean('mr')->default(false);
            $table->string('profile_picture')->nullable();
            $table->string('id_notif')->nullable();
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });
    }
}
