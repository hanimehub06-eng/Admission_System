<?php

namespace Tests;

use App\Enums\Role;
use App\Models\Application;
use App\Models\User;
use App\Services\ApplicationService;
use Database\Seeders\AdmissionSetupSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, AdmissionSetupSeeder::class]);
        // Uploads and the fake DMS write to the private disk: keep them out of storage/
        Storage::fake('local');
    }

    /**
     * A real (1×1) JPEG. UploadedFile::fake()->image() would need PHP's GD
     * extension, which some PHP builds (e.g. php.new) don't include.
     */
    protected function fakePhoto(string $name = 'photo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/'.
            'wAALCAABAAEBAREA/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0Kx'.
            'wRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2'.
            't7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oACAEBAAA/APn+iiigD//Z'
        ));
    }

    protected function makeUser(Role $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role->value);

        return $user;
    }

    /** A complete "My application" form, as the portal sends it. */
    protected function completeForm(array $overrides = []): array
    {
        return array_merge([
            'firstName' => 'Jordan', 'lastName' => 'Mendoza', 'middleName' => 'Reyes', 'phone' => '+63 917 123 4567',
            'birthDate' => '2009-03-14', 'sex' => 'Male', 'civilStatus' => 'Single', 'address' => '12 Mabini St.',
            'city' => 'Pasig City', 'province' => 'Metro Manila', 'zip' => '1600', 'guardianName' => 'Teresa Mendoza',
            'guardianPhone' => '+63 918 765 4321', 'lastSchool' => 'Rizal High School', 'schoolType' => 'Public',
            'strand' => 'STEM', 'gradYear' => '2027 (expected)', 'gwa' => '92.40', 'lrn' => '123456789012',
            'program1' => 'BS Information Technology', 'program2' => 'BS Computer Science', 'campus' => 'Main Campus',
            'step' => 3, 'completed' => [1, 2, 3],
        ], $overrides);
    }

    /** A registered applicant; with $filled, the form is complete too. */
    protected function makeApplicant(bool $filled = false, string $email = 'jordan@example.com'): User
    {
        $user = app(ApplicationService::class)->register([
            'first_name' => 'Jordan', 'last_name' => 'Mendoza', 'email' => $email,
            'phone' => '+63 917 123 4567', 'password' => 'password',
        ]);
        $user->markEmailAsVerified();
        if ($filled) {
            // The LRN is unique per applicant: derive it from the email for anyone but the default applicant
            $lrn = $email === 'jordan@example.com' ? [] : ['lrn' => sprintf('%012d', crc32($email))];
            app(ApplicationService::class)->saveForm($this->applicationOf($user), $this->completeForm($lrn), $user);
        }

        return $user;
    }

    protected function applicationOf(User $user): Application
    {
        return $user->applicant->applications()->firstOrFail();
    }

    /** Each test request starts with no logged-in user, like a real request. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        // In production every request is a new PHP process. Here the JWT
        // singletons would keep the previous request's token, so drop them.
        $this->app['auth']->forgetGuards();
        foreach (['tymon.jwt', 'tymon.jwt.auth', 'tymon.jwt.parser'] as $service) {
            $this->app->forgetInstance($service);
        }
        JWTAuth::clearResolvedInstances();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /** Act as the user through a real JWT, the way the UI calls the API. */
    protected function asUser(User $user): static
    {
        return $this->flushHeaders()->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($user));
    }
}
