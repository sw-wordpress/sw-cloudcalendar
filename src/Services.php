<?php
/**
 * Lazy service container: the only place where implementations are wired to their ports.
 *
 * @package sw-cloudcalendar
 */

declare(strict_types=1);

namespace SW\Calendar;

use SW\Calendar\Application\Access\SourceAccessService;
use SW\Calendar\Application\Attachments\AttachmentSyncService;
use SW\Calendar\Application\Port\HttpClientInterface;
use SW\Calendar\Application\Render\Component\AttachmentRenderer;
use SW\Calendar\Application\Render\EventValues;
use SW\Calendar\Application\Render\PresetLibrary;
use SW\Calendar\Application\Render\RenderLabels;
use SW\Calendar\Application\Render\RenderSettings;
use SW\Calendar\Application\Render\Template\TemplateCompiler;
use SW\Calendar\Application\Render\TemplateProvider;
use SW\Calendar\Application\Render\ViewRenderer;
use SW\Calendar\Application\Security\UrlGuard;
use SW\Calendar\Application\Sync\OccurrenceBuilder;
use SW\Calendar\Application\Sync\RecurrenceExpander;
use SW\Calendar\Application\Sync\SyncService;
use SW\Calendar\Domain\Connection\Provider;
use SW\Calendar\Domain\Source\SourceSettings;
use SW\Calendar\Infrastructure\Crypto\SecretVault;
use SW\Calendar\Infrastructure\Database\AttachmentRepository;
use SW\Calendar\Infrastructure\Database\AuditLog;
use SW\Calendar\Infrastructure\Database\ConnectionRepository;
use SW\Calendar\Infrastructure\Database\CredentialStore;
use SW\Calendar\Infrastructure\Database\DbLogger;
use SW\Calendar\Infrastructure\Database\JobQueue;
use SW\Calendar\Infrastructure\Database\OccurrenceRepository;
use SW\Calendar\Infrastructure\Database\ProviderItemStore;
use SW\Calendar\Infrastructure\Database\SourceRepository;
use SW\Calendar\Infrastructure\Database\SyncRunStore;
use SW\Calendar\Infrastructure\Database\SyncStateStore;
use SW\Calendar\Infrastructure\Database\TemplateRepository;
use SW\Calendar\Infrastructure\Database\ViewRepository;
use SW\Calendar\Infrastructure\Database\WpdbTransaction;
use SW\Calendar\Infrastructure\Http\WpHttpClient;
use SW\Calendar\Infrastructure\OAuth\OAuthEndpoints;
use SW\Calendar\Infrastructure\OAuth\OAuthService;
use SW\Calendar\Infrastructure\Provider\AuthorizedApi;
use SW\Calendar\Infrastructure\Provider\Google\GoogleCalendarProvider;
use SW\Calendar\Infrastructure\Provider\Ics\IcsProvider;
use SW\Calendar\Infrastructure\Provider\Microsoft\GraphCalendarProvider;
use SW\Calendar\Infrastructure\Provider\ProviderRegistry;
use SW\Calendar\Infrastructure\Storage\FinfoMimeDetector;
use SW\Calendar\Infrastructure\Storage\PrivateFileStorage;
use SW\Calendar\Infrastructure\WordPress\HookInvalidator;
use SW\Calendar\Infrastructure\WordPress\KsesSanitizer;
use SW\Calendar\Infrastructure\WordPress\Settings;
use SW\Calendar\Infrastructure\WordPress\StorageGuard;
use SW\Calendar\Infrastructure\WordPress\TransientCache;
use SW\Calendar\Infrastructure\WordPress\WpDateFormatter;
use SW\Calendar\Infrastructure\WordPress\WpImagePreview;
use SW\Calendar\Infrastructure\WordPress\WpUrlGenerator;
use SW\Calendar\Infrastructure\WordPress\WpUserContext;
use SW\Calendar\Support\ClockInterface;
use SW\Calendar\Support\SystemClock;

final class Services {

	private static ?Services $instance = null;

	/** @var array<string, object> */
	private array $services = array();

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/** Test hook: replaces the container (e.g. with a frozen clock). */
	public static function reset( ?self $instance = null ): void {
		self::$instance = $instance;
	}

	/**
	 * @template T of object
	 * @param class-string<T>|string $id
	 * @param callable(): T          $factory
	 * @return T
	 */
	private function share( string $id, callable $factory ): object {
		return $this->services[ $id ] ??= $factory();
	}

	/** Overrides a service (tests). */
	public function set( string $id, object $service ): void {
		$this->services[ $id ] = $service;
	}

	public function clock(): ClockInterface {
		return $this->share( 'clock', static fn (): ClockInterface => new SystemClock() );
	}

	public function logger(): DbLogger {
		return $this->share( 'logger', static fn (): DbLogger => new DbLogger( (string) Settings::get( 'log_level' ) ) );
	}

	public function vault(): SecretVault {
		return $this->share(
			'vault',
			static function (): SecretVault {
				$primary  = defined( 'SW_CALENDAR_ENCRYPTION_KEY' ) ? (string) SW_CALENDAR_ENCRYPTION_KEY : ( getenv( 'SW_CALENDAR_ENCRYPTION_KEY' ) ?: null );
				$previous = defined( 'SW_CALENDAR_ENCRYPTION_KEY_PREVIOUS' ) ? (string) SW_CALENDAR_ENCRYPTION_KEY_PREVIOUS : ( getenv( 'SW_CALENDAR_ENCRYPTION_KEY_PREVIOUS' ) ?: null );
				return new SecretVault( $primary, $previous, wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) );
			}
		);
	}

	public function credentials(): CredentialStore {
		return $this->share( 'credentials', fn (): CredentialStore => new CredentialStore( $this->vault() ) );
	}

	public function connections(): ConnectionRepository {
		return $this->share( 'connections', static fn (): ConnectionRepository => new ConnectionRepository() );
	}

	public function max_window_days(): int {
		return defined( 'SW_CALENDAR_MAX_WINDOW_DAYS' ) ? max( 1, (int) SW_CALENDAR_MAX_WINDOW_DAYS ) : SourceSettings::DEFAULT_MAX_WINDOW_DAYS;
	}

	public function sources(): SourceRepository {
		return $this->share( 'sources', fn (): SourceRepository => new SourceRepository( $this->max_window_days() ) );
	}

	public function views(): ViewRepository {
		return $this->share( 'views', static fn (): ViewRepository => new ViewRepository() );
	}

	public function templates(): TemplateRepository {
		return $this->share( 'templates', static fn (): TemplateRepository => new TemplateRepository() );
	}

	public function occurrences(): OccurrenceRepository {
		return $this->share( 'occurrences', static fn (): OccurrenceRepository => new OccurrenceRepository() );
	}

	public function attachments(): AttachmentRepository {
		return $this->share( 'attachments', static fn (): AttachmentRepository => new AttachmentRepository() );
	}

	public function items(): ProviderItemStore {
		return $this->share( 'items', fn (): ProviderItemStore => new ProviderItemStore( $this->logger() ) );
	}

	public function sync_state(): SyncStateStore {
		return $this->share( 'sync_state', fn (): SyncStateStore => new SyncStateStore( $this->vault() ) );
	}

	public function sync_runs(): SyncRunStore {
		return $this->share( 'sync_runs', static fn (): SyncRunStore => new SyncRunStore() );
	}

	public function jobs(): JobQueue {
		return $this->share( 'jobs', static fn (): JobQueue => new JobQueue() );
	}

	public function audit(): AuditLog {
		return $this->share( 'audit', static fn (): AuditLog => new AuditLog() );
	}

	public function cache(): TransientCache {
		return $this->share( 'cache', static fn (): TransientCache => new TransientCache() );
	}

	public function access(): SourceAccessService {
		return $this->share( 'access', fn (): SourceAccessService => new SourceAccessService( $this->sources(), new WpUserContext() ) );
	}

	public function http(): HttpClientInterface {
		return $this->share( 'http', static fn (): HttpClientInterface => new WpHttpClient( new UrlGuard() ) );
	}

	public function oauth(): OAuthService {
		return $this->share( 'oauth', fn (): OAuthService => new OAuthService( $this->http(), $this->credentials(), $this->clock() ) );
	}

	public function providers(): ProviderRegistry {
		return $this->share(
			'providers',
			fn (): ProviderRegistry => new ProviderRegistry(
				array(
					Provider::Ics->value       => fn () => new IcsProvider( $this->http(), $this->credentials() ),
					Provider::Google->value    => fn () => new GoogleCalendarProvider( new AuthorizedApi( $this->http(), $this->oauth(), $this->credentials(), OAuthEndpoints::GOOGLE_HOSTS ) ),
					Provider::Microsoft->value => fn () => new GraphCalendarProvider( new AuthorizedApi( $this->http(), $this->oauth(), $this->credentials(), OAuthEndpoints::MICROSOFT_HOSTS ) ),
				)
			)
		);
	}

	public function sanitizer(): KsesSanitizer {
		return $this->share( 'sanitizer', static fn (): KsesSanitizer => new KsesSanitizer() );
	}

	public function invalidator(): HookInvalidator {
		return $this->share( 'invalidator', static fn (): HookInvalidator => new HookInvalidator() );
	}

	public function sync(): SyncService {
		return $this->share(
			'sync',
			fn (): SyncService => new SyncService(
				$this->sources(),
				$this->connections(),
				$this->providers(),
				$this->items(),
				$this->occurrences(),
				$this->sync_state(),
				$this->sync_runs(),
				$this->attachments(),
				$this->jobs(),
				new WpdbTransaction( defined( 'SW_CALENDAR_TEST_SAVEPOINTS' ) && SW_CALENDAR_TEST_SAVEPOINTS ),
				$this->invalidator(),
				new OccurrenceBuilder( new RecurrenceExpander(), $this->sanitizer() ),
				$this->clock(),
				$this->logger()
			)
		);
	}

	public function storage_guard(): StorageGuard {
		return $this->share( 'storage_guard', static fn (): StorageGuard => new StorageGuard() );
	}

	public function storage(): PrivateFileStorage {
		return $this->share( 'storage', fn (): PrivateFileStorage => $this->storage_guard()->storage() );
	}

	public function attachment_sync(): AttachmentSyncService {
		return $this->share(
			'attachment_sync',
			fn (): AttachmentSyncService => new AttachmentSyncService(
				$this->sources(),
				$this->connections(),
				$this->providers(),
				$this->attachments(),
				$this->storage(),
				$this->storage_guard(),
				new FinfoMimeDetector(),
				new WpImagePreview(),
				$this->invalidator(),
				$this->clock(),
				$this->logger(),
				(int) Settings::get( 'global_quota_mb' ) * 1024 * 1024
			)
		);
	}

	public function urls(): WpUrlGenerator {
		return $this->share( 'urls', static fn (): WpUrlGenerator => new WpUrlGenerator() );
	}

	public function dates(): WpDateFormatter {
		return $this->share( 'dates', static fn (): WpDateFormatter => new WpDateFormatter() );
	}

	public function presets(): PresetLibrary {
		return $this->share( 'presets', static fn (): PresetLibrary => new PresetLibrary( SW_CALENDAR_DIR . 'templates/presets' ) );
	}

	public function compiler(): TemplateCompiler {
		return $this->share( 'compiler', static fn (): TemplateCompiler => new TemplateCompiler() );
	}

	public function template_provider(): TemplateProvider {
		return $this->share( 'template_provider', fn (): TemplateProvider => new TemplateProvider( $this->templates(), $this->presets(), $this->compiler(), $this->cache(), $this->logger() ) );
	}

	public function labels(): RenderLabels {
		return $this->share(
			'labels',
			static function (): RenderLabels {
				global $wp_locale;
				return new RenderLabels(
					__( 'No events.', 'sw-cloudcalendar' ),
					__( 'Previous month', 'sw-cloudcalendar' ),
					__( 'Next month', 'sw-cloudcalendar' ),
					__( 'Calendar navigation', 'sw-cloudcalendar' ),
					__( 'Images', 'sw-cloudcalendar' ),
					__( 'Files', 'sw-cloudcalendar' ),
					/* translators: %s: file name */
					__( 'Download %s', 'sw-cloudcalendar' ),
					__( 'All day', 'sw-cloudcalendar' ),
					__( 'Today', 'sw-cloudcalendar' ),
					/* translators: %d: number of further events */
					__( '%d more', 'sw-cloudcalendar' ),
					/* translators: 1: date, 2: number of events */
					__( '%1$s, %2$d events', 'sw-cloudcalendar' ),
					__( 'Loading calendar…', 'sw-cloudcalendar' ),
					__( 'Please enable JavaScript to see this calendar.', 'sw-cloudcalendar' ),
					isset( $wp_locale ) ? (string) $wp_locale->number_format['decimal_point'] : '.',
					isset( $wp_locale ) ? (string) $wp_locale->number_format['thousands_sep'] : ',',
					__( 'Preview', 'sw-cloudcalendar' )
				);
			}
		);
	}

	/** Drops services that captured translated strings or the locale (after switch_to_locale()). */
	public function forget_locale_dependent(): void {
		unset( $this->services['labels'], $this->services['renderer'] );
	}

	public function renderer(): ViewRenderer {
		return $this->share(
			'renderer',
			fn (): ViewRenderer => new ViewRenderer(
				$this->views(),
				$this->sources(),
				$this->access(),
				$this->occurrences(),
				$this->attachments(),
				$this->template_provider(),
				new EventValues( $this->dates(), $this->urls(), $this->sanitizer(), new AttachmentRenderer( $this->urls(), $this->labels() ) ),
				$this->dates(),
				$this->urls(),
				$this->cache(),
				$this->clock(),
				new RenderSettings(
					determine_locale(),
					(int) get_option( 'start_of_week', 1 ),
					(bool) Settings::get( 'html_cache' ),
					Settings::site_timezone(),
					$this->labels()
				)
			)
		);
	}
}
