<?php
/**
 * Résultat d'une soumission.
 *
 * Objet de valeur immuable plutôt qu'un tableau libre : le contrôleur, les
 * journaux, la redirection et les bancs d'essai lisent tous la même forme, et
 * une clé mal orthographiée devient une erreur au lieu d'un silence.
 *
 * Les codes sont **internes**. Ils nomment ce qui s'est passé pour le journal
 * et pour les tests ; ils ne sont jamais montrés au prospect. L'interface
 * publique de la PR C les traduira en une phrase compréhensible — et
 * volontairement peu bavarde sur les refus de sécurité.
 *
 * @package Urbizen\Platform
 */

namespace Urbizen\Platform\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Issue d'une tentative de soumission.
 */
final class SubmissionResult {

	// --- Refus avant tout traitement ---
	public const INVALID_METHOD          = 'invalid_method';
	public const INVALID_NONCE           = 'invalid_nonce';
	public const SPAM_HONEYPOT           = 'spam_honeypot';
	public const INVALID_ANTISPAM_TOKEN  = 'invalid_antispam_token';
	public const TOKEN_TOO_FAST          = 'token_too_fast';
	public const TOKEN_EXPIRED           = 'token_expired';
	public const DUPLICATE_SUBMISSION    = 'duplicate_submission';
	public const RATE_LIMITED            = 'rate_limited';

	// --- Documents ---
	// Le code `files_not_supported_yet` de la PR B1 disparaît ici : les
	// documents sont désormais réellement traités.
	public const UPLOAD_TOO_LARGE           = 'upload_too_large';
	public const UPLOAD_PARTIAL             = 'upload_partial';
	public const UPLOAD_MISSING_TMP         = 'upload_missing_tmp';
	public const UPLOAD_WRITE_FAILED        = 'upload_write_failed';
	public const UPLOAD_BLOCKED             = 'upload_blocked';
	public const UPLOAD_INVALID_STRUCTURE   = 'upload_invalid_structure';
	public const UPLOAD_EMPTY_FILE          = 'upload_empty_file';
	public const UPLOAD_INVALID_EXTENSION   = 'upload_invalid_extension';
	public const UPLOAD_INVALID_MIME        = 'upload_invalid_mime';
	public const UPLOAD_COUNT_EXCEEDED      = 'upload_count_exceeded';
	public const UPLOAD_TOTAL_SIZE_EXCEEDED = 'upload_total_size_exceeded';
	public const STORAGE_UNAVAILABLE        = 'storage_unavailable';
	public const STORAGE_FAILED             = 'storage_failed';
	public const FILE_METADATA_FAILED       = 'file_metadata_failed';
	public const FILE_INTEGRITY_FAILED      = 'file_integrity_failed';
	public const REQUEST_TOO_LARGE          = 'request_too_large';
	public const SERVER_UPLOAD_LIMIT        = 'server_upload_limit_exceeded';
	public const INVALID_FORM            = 'invalid_form';
	public const VALIDATION_FAILED       = 'validation_failed';
	public const PRICING_FAILED          = 'pricing_failed';
	public const PERSISTENCE_FAILED      = 'persistence_failed';

	// --- Issue heureuse ---
	public const SUCCESS = 'success';

	/**
	 * Tous les codes reconnus.
	 *
	 * @var array<int, string>
	 */
	public const CODES = array(
		self::INVALID_METHOD,
		self::INVALID_NONCE,
		self::SPAM_HONEYPOT,
		self::INVALID_ANTISPAM_TOKEN,
		self::TOKEN_TOO_FAST,
		self::TOKEN_EXPIRED,
		self::DUPLICATE_SUBMISSION,
		self::RATE_LIMITED,
		self::UPLOAD_TOO_LARGE,
		self::UPLOAD_PARTIAL,
		self::UPLOAD_MISSING_TMP,
		self::UPLOAD_WRITE_FAILED,
		self::UPLOAD_BLOCKED,
		self::UPLOAD_INVALID_STRUCTURE,
		self::UPLOAD_EMPTY_FILE,
		self::UPLOAD_INVALID_EXTENSION,
		self::UPLOAD_INVALID_MIME,
		self::UPLOAD_COUNT_EXCEEDED,
		self::UPLOAD_TOTAL_SIZE_EXCEEDED,
		self::STORAGE_UNAVAILABLE,
		self::STORAGE_FAILED,
		self::FILE_METADATA_FAILED,
		self::FILE_INTEGRITY_FAILED,
		self::REQUEST_TOO_LARGE,
		self::SERVER_UPLOAD_LIMIT,
		self::INVALID_FORM,
		self::VALIDATION_FAILED,
		self::PRICING_FAILED,
		self::PERSISTENCE_FAILED,
		self::SUCCESS,
	);

	/**
	 * Succès ou échec.
	 *
	 * @var bool
	 */
	private bool $ok;

	/**
	 * Code interne.
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * Référence attribuée, en cas de succès.
	 *
	 * @var string
	 */
	private string $reference;

	/**
	 * Identifiant WordPress de la demande, en cas de succès.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Erreurs de validation, par identifiant de champ.
	 *
	 * @var array<string, string>
	 */
	private array $errors;

	/**
	 * Destination de redirection.
	 *
	 * @var string
	 */
	private string $redirect;

	/**
	 * Constructeur privé : on passe par les fabriques.
	 *
	 * @param bool                  $ok        Succès.
	 * @param string                $code      Code interne.
	 * @param string                $reference Référence.
	 * @param int                   $id        Identifiant.
	 * @param array<string, string> $errors    Erreurs de validation.
	 * @param string                $redirect  Destination.
	 */
	private function __construct(
		bool $ok,
		string $code,
		string $reference = '',
		int $id = 0,
		array $errors = array(),
		string $redirect = ''
	) {
		$this->ok        = $ok;
		$this->code      = $code;
		$this->reference = $reference;
		$this->id        = $id;
		$this->errors    = $errors;
		$this->redirect  = $redirect;
	}

	/**
	 * Échec.
	 *
	 * @param string                $code   Code interne.
	 * @param array<string, string> $errors Erreurs de validation.
	 * @return self
	 */
	public static function failure( string $code, array $errors = array() ): self {
		return new self( false, $code, '', 0, $errors );
	}

	/**
	 * Succès.
	 *
	 * @param string $reference Référence attribuée.
	 * @param int    $id        Identifiant WordPress.
	 * @return self
	 */
	public static function success( string $reference, int $id ): self {
		return new self( true, self::SUCCESS, $reference, $id );
	}

	/**
	 * Copie portant une destination de redirection.
	 *
	 * @param string $url Destination.
	 * @return self
	 */
	public function with_redirect( string $url ): self {
		return new self( $this->ok, $this->code, $this->reference, $this->id, $this->errors, $url );
	}

	/**
	 * Succès ?
	 */
	public function is_success(): bool {
		return $this->ok;
	}

	/**
	 * Code interne.
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Référence attribuée.
	 */
	public function reference(): string {
		return $this->reference;
	}

	/**
	 * Identifiant WordPress.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Erreurs de validation, par champ.
	 *
	 * @return array<string, string>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Destination de redirection.
	 */
	public function redirect(): string {
		return $this->redirect;
	}
}
