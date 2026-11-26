<?php

declare(strict_types=1);

namespace Cpdc;

use Cpdc\Alumni\AlumniController;
use Cpdc\Audit\AuditController;
use Cpdc\Auth\Access;
use Cpdc\Auth\AuthController;
use Cpdc\Auth\GitHubController;
use Cpdc\Auth\GoogleOidc;
use Cpdc\Auth\LinkedInController;
use Cpdc\Auth\LinkedInOidc;
use Cpdc\Http\ApiError;
use Cpdc\Http\ErrorHandler;
use Cpdc\Http\Middleware\Csrf;
use Cpdc\Http\Middleware\RateLimit;
use Cpdc\Http\Middleware\Session;
use Cpdc\Http\Request;
use Cpdc\Http\Response;
use Cpdc\Http\Router;
use Cpdc\Http\SecurityHeaders;
use Cpdc\Jobs\JobsController;
use Cpdc\Jobs\ManageController;
use Cpdc\Learning\LearningController;
use Cpdc\People\PeopleController;
use Cpdc\Profile\Onboarding;
use Cpdc\Profile\ProfileController;
use Cpdc\Settings\Settings;
use Cpdc\Skills\SkillsController;
use PDO;
// slice 3a (workshops, CV, career path)
use Cpdc\CareerPath\CareerPathController;
use Cpdc\Cv\CvController;
use Cpdc\Workshops\WorkshopController;

/**
 * Request pipeline: SecurityHeaders, ErrorHandler, Session, per-IP or per-user limit, route match, Auth (401),
 * onboarding gate (403), Csrf, route limit, route.
 */
final class App
{
    private ?PDO $pdo = null;
    private ?Access $access = null;

    /** @var array<int, Settings> loaded once per request per organisation */
    private array $settings = [];

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param \Closure(): PDO $connect called only when a request needs the database
     * @param (\Closure(): int)|null $clock unix time; tests pass a fixed clock
     * @param (\Closure(): GoogleOidc)|null $google called only by the Google sign-in routes
     * @param (\Closure(): ?LinkedInOidc)|null $linkedIn null client = LinkedIn not set up
     */
    public function __construct(
        private readonly Router $router,
        private readonly \Closure $connect,
        public readonly string $appUrl,
        public readonly string $storageDir,
        public readonly int $defaultOrganisationId,
        ?\Closure $clock = null,
        private readonly ?\Closure $google = null,
        private readonly ?\Closure $linkedIn = null
    ) {
        $this->clock = $clock ?? time(...);
    }

    /** Production routes. */
    public static function routes(Router $router): Router
    {
        // ---- slice 2a (logbook, showcase, assessments): begin ----
        \Cpdc\Logbook\Slice2aRoutes::add($router);
        // ---- slice 2a: end ----
        $login = fn (string $name) => ['name' => $name, 'setting' => 'rate_login_per_ip_per_min', 'by' => 'ip'];
        $export = ['name' => 'export', 'setting' => 'rate_export_per_user_per_min', 'by' => 'user'];
        $pdf = ['name' => 'pdf-import', 'setting' => 'rate_pdf_import_per_user_per_min', 'by' => 'user'];

        // --- slice 3b: alumni network, mentoring, interview practice (begin) ---
        \Cpdc\Network\NetworkRoutes::add($router);
        \Cpdc\Interview\InterviewRoutes::add($router);
        // --- slice 3b (end) ---

        // --- slice B: home, yearly survey, self-assessment reports (begin) ---
        \Cpdc\Survey\SliceBRoutes::add($router);
        // --- slice B (end) ---

        $router
            ->add('GET', '/api/health', fn () => Response::json(['status' => 'ok']), public: true)
            ->add('GET', '/api/auth/google/start', AuthController::start(...), true, $login('google-start'))
            ->add('GET', '/api/auth/google/callback', AuthController::callback(...), true, $login('google-callback'))
            ->add('GET', '/api/auth/linkedin/start', LinkedInController::start(...), true, $login('linkedin-start'))
            ->add(
                'GET',
                LinkedInOidc::CALLBACK_PATH,
                LinkedInController::callback(...),
                true,
                $login('linkedin-callback')
            )
            ->add('GET', '/api/auth/github/start', GitHubController::start(...), true, $login('github-start'))
            ->add(
                'GET',
                GitHubController::CALLBACK_PATH,
                GitHubController::callback(...),
                true,
                $login('github-callback')
            )
            ->add('POST', '/api/auth/logout', AuthController::logout(...), beforeOnboarding: true)
            ->add('GET', '/api/me', AuthController::me(...), beforeOnboarding: true)
            ->add(
                'POST',
                '/api/onboarding/complete',
                ProfileController::completeOnboarding(...),
                beforeOnboarding: true
            )
            ->add('GET', '/api/graduates/registration', AlumniController::pendingRegistration(...), public: true)
            ->add('POST', '/api/graduates/registration', AlumniController::register(...), true, $login('register'))
            ->add('GET', '/api/profile', ProfileController::show(...))
            ->add('PUT', '/api/profile', ProfileController::save(...))
            ->add('POST', '/api/profile/linkedin-pdf', ProfileController::readPdf(...), rate: $pdf)
            ->add('PATCH', '/api/profile/linkedin-url', ProfileController::changeLinkedInUrl(...))
            ->add('POST', '/api/profile/job-confirmation', ProfileController::confirmJob(...))
            ->add('GET', '/api/people', PeopleController::list(...))
            ->add('GET', '/api/people/{id}', PeopleController::show(...))
            ->add('PATCH', '/api/people/{id}/role', PeopleController::role(...))
            ->add('POST', '/api/people/{id}/alumni', AlumniController::convertOne(...))
            ->add('POST', '/api/alumni/convert', AlumniController::convertList(...))
            ->add('GET', '/api/alumni', AlumniController::list(...))
            ->add('GET', '/api/alumni/stats', AlumniController::stats(...))
            ->add('GET', '/api/alumni/export', AlumniController::export(...), rate: $export)
            ->add('GET', '/api/registrations', AlumniController::registrations(...))
            ->add('POST', '/api/registrations/{id}/approve', AlumniController::approve(...))
            ->add('POST', '/api/registrations/{id}/refuse', AlumniController::refuse(...))
            ->add('GET', '/api/audit', AuditController::list(...))
            // --- slice 3a (workshops, CV, career path): begin ---
            ->add('GET', '/api/workshops', WorkshopController::list(...))
            ->add('POST', '/api/workshops', WorkshopController::create(...))
            ->add('GET', '/api/workshops/{id}', WorkshopController::show(...))
            ->add('PUT', '/api/workshops/{id}', WorkshopController::update(...))
            ->add('POST', '/api/workshops/{id}/cancel', WorkshopController::cancel(...))
            ->add('POST', '/api/workshops/{id}/rsvp', WorkshopController::rsvp(...))
            ->add('DELETE', '/api/workshops/{id}/rsvp', WorkshopController::cancelRsvp(...))
            ->add('POST', '/api/workshops/{id}/check-in', WorkshopController::checkIn(...), rate: [
                'name' => 'workshop-checkin', 'setting' => 'rate_checkin_per_user_per_min', 'by' => 'user',
            ])
            ->add('GET', '/api/workshops/{id}/attendees', WorkshopController::attendees(...))
            ->add('PUT', '/api/workshops/{id}/attendance', WorkshopController::attendance(...))
            ->add('GET', '/api/workshops/{id}/export', WorkshopController::export(...), rate: $export)
            ->add('GET', '/api/cv', CvController::show(...))
            ->add('PUT', '/api/cv', CvController::save(...))
            ->add('GET', '/api/career-paths', CareerPathController::overview(...))
            ->add('POST', '/api/career-paths', CareerPathController::create(...))
            ->add('PUT', '/api/career-paths/{id}', CareerPathController::update(...))
            ->add('DELETE', '/api/career-paths/{id}', CareerPathController::delete(...))
            ->add('PUT', '/api/career-paths/steps/{id}', CareerPathController::progress(...));
            // --- slice 3a: end ---

        // --- slice 2b: employers and jobs, skills diagnostics, learning (start) ---
        $upload = ['name' => 'pdf-upload', 'setting' => 'rate_upload_per_user_per_min', 'by' => 'user'];
        $router
            ->add('GET', '/api/jobs', JobsController::list(...))
            ->add('GET', '/api/jobs/{id}', JobsController::show(...))
            ->add('POST', '/api/jobs/{id}/apply', JobsController::apply(...), rate: $upload)
            ->add('GET', '/api/applications/mine', JobsController::mine(...))
            ->add('POST', '/api/applications/{id}/withdraw', JobsController::withdraw(...))
            ->add('GET', '/api/applications/{id}/cv', JobsController::cv(...))
            ->add('GET', '/api/applications/{id}', ManageController::application(...))
            ->add('PATCH', '/api/applications/{id}/status', ManageController::status(...))
            ->add('GET', '/api/employers', ManageController::employers(...))
            ->add('POST', '/api/employers', ManageController::createEmployer(...))
            ->add('PUT', '/api/employers/{id}', ManageController::updateEmployer(...))
            ->add('GET', '/api/jobs/manage', ManageController::jobs(...))
            ->add('POST', '/api/jobs/manage', ManageController::createJob(...))
            ->add('GET', '/api/jobs/manage/{id}', ManageController::job(...))
            ->add('PUT', '/api/jobs/manage/{id}', ManageController::updateJob(...))
            ->add('GET', '/api/jobs/manage/{id}/applications', ManageController::applications(...))
            ->add('GET', '/api/skills', SkillsController::show(...))
            ->add('GET', '/api/skills/areas', SkillsController::areas(...))
            ->add('POST', '/api/skills/assessments', SkillsController::rate(...))
            ->add('GET', '/api/skills/gap/{id}', SkillsController::gap(...))
            ->add('GET', '/api/learning', LearningController::mine(...))
            ->add('POST', '/api/learning', LearningController::add(...), rate: $upload)
            ->add('GET', '/api/learning/review', LearningController::review(...))
            ->add('DELETE', '/api/learning/{id}', LearningController::delete(...))
            ->add('GET', '/api/learning/{id}/proof', LearningController::proof(...))
            ->add('POST', '/api/learning/{id}/decision', LearningController::decide(...));
        // --- slice 2b (end) ---

        return $router;
    }

    public function handle(Request $req): Response
    {
        $this->settings = [];
        $this->access = null;
        try {
            $authError = $this->authenticate($req);
            // Anonymous callers share one budget per IP; signed-in users get their own (a campus sits behind NAT).
            $limiter = new RateLimit($this->pdo(), $this->clock);
            if ($req->user === null) {
                $limit = $this->settings($this->defaultOrganisationId)->int('rate_limit_ip_per_minute');
                $limiter->hit('ip-all', 'ip:' . $req->remoteAddr, $limit);
            } else {
                $limit = $this->settings($req->user['organisation_id'])->int('rate_limit_user_per_minute');
                $limiter->hit('user-all', 'user:' . $req->user['id'], $limit);
            }
            $route = $this->router->match($req->method, $req->path);
            if (!$route['public'] && $req->user === null) {
                throw $authError;
            }
            $gated = !$route['public'] && !$route['beforeOnboarding'];
            $user = $req->user;
            if ($gated && is_array($user) && Onboarding::blocks($user, $this->linkedInConfigured())) {
                throw new ApiError(403, 'ONBOARDING_REQUIRED', 'Link your LinkedIn account first.');
            }
            if (!in_array($req->method, ['GET', 'HEAD'], true)) {
                Csrf::check($req, $this->appUrl, needsToken: !$route['public']);
            }
            if ($route['rate'] !== null) {
                $this->rateLimit($req, $route['rate']);
            }
            $response = ($route['handler'])($req, $route['params'], $this);
        } catch (\Throwable $e) {
            $response = (new ErrorHandler($this->storageDir . '/logs'))->handle($e, $req);
            // A browser navigation, not a fetch: a limited sign-in goes to the sign-in page, never raw JSON.
            if ($response->status === 429 && $req->method === 'GET' && str_starts_with($req->path, '/api/auth/')) {
                $response = Response::redirect('/login?error=busy');
            }
        } finally {
            Session::close();
        }
        if ($req->method === 'HEAD') {
            $response = new Response($response->status, '', $response->headers);
        }

        return SecurityHeaders::apply($response);
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= ($this->connect)();
    }

    public function settings(int $organisationId): Settings
    {
        return $this->settings[$organisationId] ??= Settings::load($this->pdo(), $organisationId);
    }

    public function session(): Session
    {
        return new Session($this->pdo(), fn (int $org) => $this->settings($org), $this->clock);
    }

    public function access(): Access
    {
        return $this->access ??= new Access($this->pdo());
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    public function google(): GoogleOidc
    {
        return $this->google === null ? GoogleOidc::fromEnv($this->appUrl, $this->storageDir) : ($this->google)();
    }

    public function linkedIn(): ?LinkedInOidc
    {
        return $this->linkedIn === null ? LinkedInOidc::fromEnv($this->appUrl) : ($this->linkedIn)();
    }

    public function linkedInConfigured(): bool
    {
        return $this->linkedIn === null
            ? trim((string) getenv('LINKEDIN_CLIENT_ID')) !== ''
                && trim((string) getenv('LINKEDIN_CLIENT_SECRET')) !== ''
            : ($this->linkedIn)() !== null;
    }

    public function gitHubConfigured(): bool
    {
        return trim((string) getenv('GITHUB_CLIENT_ID')) !== '' && trim((string) getenv('GITHUB_CLIENT_SECRET')) !== '';
    }

    /** Sets $req->user when the cookie holds a valid session; otherwise returns the 401 to raise later. */
    private function authenticate(Request $req): ApiError
    {
        try {
            $req->user = $this->session()->authenticate($req);
        } catch (ApiError $e) {
            return $e;
        }

        return new ApiError(401, 'UNAUTHENTICATED', 'Please sign in.');
    }

    /** @param array{name: string, setting: string, by: 'ip'|'user', window?: int} $rate */
    private function rateLimit(Request $req, array $rate): void
    {
        // REMOTE_ADDR only: X-Forwarded-For is client-controlled (Apache remoteip sets it behind a known proxy).
        $byUser = $rate['by'] === 'user' && $req->user !== null;
        $subject = $byUser ? 'user:' . $req->user['id'] : 'ip:' . $req->remoteAddr;
        $org = $byUser ? $req->user['organisation_id'] : $this->defaultOrganisationId;
        $limit = $this->settings($org)->int($rate['setting']);
        (new RateLimit($this->pdo(), $this->clock))
            ->hit($rate['name'], $subject, $limit, $rate['window'] ?? RateLimit::MINUTE);
    }
}
