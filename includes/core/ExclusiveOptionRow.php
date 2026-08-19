<?php


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exclusive occupancy of a single WordPress options row: at most one request
 * can hold a given option name, and the DATABASE decides which one.
 *
 * Every mutual-exclusion mechanism in this plugin that lives in the options
 * table goes through here, because the obvious ways to write one are both
 * wrong on a site with no persistent object cache (which is the default):
 *
 *   - Reading the row with get_option() and then writing it. WordPress serves
 *     get_option() from the object cache and update_option() primes that cache
 *     with the value it just wrote, so each racing request reads back its OWN
 *     write and every one of them concludes it won. Error report 270
 *     (dianthus.zuidplas.net, 4.3.3, LiteSpeed, MySQL 8.0.46) is what that
 *     looks like in production: two requests ran the 4.3.2 to 4.3.3 database
 *     upgrade in the same second, each holding what it believed was the
 *     exclusive 'update_db_version' lock.
 *
 *   - Trusting add_option() to return false when the row already exists.
 *     WordPress guards it with that same cache-served get_option(), and since
 *     6.4 the write underneath is `INSERT ... ON DUPLICATE KEY UPDATE`, which
 *     overwrites rather than failing. Two concurrent callers can therefore
 *     both be told they added it. (On older WordPress the write was a plain
 *     INSERT that a duplicate key did reject, so this is a claim that used to
 *     hold and quietly stopped holding.)
 *
 * What does arbitrate is the options table's own UNIQUE(option_name) index:
 * an INSERT can satisfy it exactly once no matter how many arrive together.
 * So claim() is an INSERT, and every read here is direct SQL rather than
 * get_option(), because the answer to "who holds this" can only come from the
 * one store all the requests share.
 *
 * This class deliberately knows nothing about WHY a row is being held: no
 * timeouts, no owner ids, no stale-record policy. Those differ per caller
 * (ABJ_404_Solution_SynchronizationUtils breaks a lock on age and releases by
 * owner id; the rebuild locks expire on a TTL) and belong with the caller.
 */
class ABJ_404_Solution_ExclusiveOptionRow {

	/** Rows live in the options table of the blog serving the request. */
	const SCOPE_CURRENT_BLOG = 'current_blog';

	/** Rows live in the options table of the network's MAIN SITE, so every blog
	 * in a multisite network contends for the same row.
	 *
	 * This is where a network-wide claim has to go, rather than into sitemeta
	 * where get_site_option() would put it: sitemeta indexes meta_key
	 * non-uniquely, so it cannot arbitrate anything -- N concurrent inserts all
	 * succeed there. The main site's options table is the one core table that
	 * is both visible to every blog in the network and uniquely keyed. */
	const SCOPE_NETWORK_MAIN_SITE = 'network_main_site';

	/** @var string one of the SCOPE_* constants */
	private $scope;

	/**
	 * @param string $scope one of the SCOPE_* constants. Defaults to the blog
	 *   serving the request, which is what every non-network claim wants.
	 */
	public function __construct($scope = self::SCOPE_CURRENT_BLOG) {
		$this->scope = $scope === self::SCOPE_NETWORK_MAIN_SITE
			? self::SCOPE_NETWORK_MAIN_SITE
			: self::SCOPE_CURRENT_BLOG;
	}

	/** Take $optionName, but only if no row exists for it yet.
	 *
	 * @param string $optionName
	 * @param string $value what to record in the row; the caller decides what
	 *   it means (an owner id, an acquisition timestamp, an expiry).
	 * @return bool true only if this call created the row.
	 */
	public function claim($optionName, $value) {
		$wpdb = $this->wpdbOrNull();
		$table = $wpdb === null ? '' : $this->optionsTable($wpdb);
		if ($wpdb === null || $table === '') {
			return false;
		}

		$boundRow = $this->bind($wpdb, '%s, %s', array($optionName, (string)$value));
		if ($boundRow === '') {
			return false;
		}

		// autoload 'no' on purpose: an autoloaded row is loaded into the
		// alloptions cache of every single request on the site, which for a
		// row that exists only while somebody holds it is pure overhead.
		// DAO-bypass-approved: this is the mutual-exclusion primitive itself; the DAO's retry-and-repair path runs INSIDE synchronized sections, and create_db_tables / update_db_version are locked while the DAO is still booting.
		$rowsInserted = $wpdb->query("INSERT IGNORE INTO `" . $table . "` "
			. "(option_name, option_value, autoload) VALUES (" . $boundRow . ", 'no')");

		// IGNORE turns the duplicate-key rejection into zero affected rows, so
		// "somebody else got there first" arrives as data rather than as an
		// error the site admin would be emailed about.
		//
		// A hard failure has to be told apart from that, even though both mean
		// "not claimed" to the caller. Losing a race is the protocol working
		// and happens constantly; a statement the engine refused (a read-only
		// replica, a full disk, an options table missing the autoload column)
		// means NOBODY can ever take this lock, which silently disables every
		// synchronized section that depends on it. Indistinguishable in
		// behavior, opposite in meaning, so the second one gets logged.
		if ($rowsInserted === false) {
			$this->logStorageFailure('claim the options row "' . $optionName . '"', $wpdb);
			return false;
		}

		return ((int)$rowsInserted) === 1;
	}

	/** The value currently recorded for $optionName, read from the table and
	 * not from WordPress's option cache.
	 *
	 * @param string $optionName
	 * @return string '' when no row exists, or when the storage cannot answer.
	 */
	public function valueOf($optionName) {
		$wpdb = $this->wpdbOrNull();
		$table = $wpdb === null ? '' : $this->optionsTable($wpdb);
		if ($wpdb === null || $table === '') {
			return '';
		}

		$boundName = $this->bind($wpdb, '%s', array($optionName));
		if ($boundName === '') {
			return '';
		}

		// DAO-bypass-approved: this row is what the DAO's own bootstrap locks on (create_db_tables, update_db_version), and it lives in WordPress's options table, which the DAO's recovery path must never CREATE, REPAIR, or raise a missing-plugin-table notice about.
		$value = $wpdb->get_var("SELECT option_value FROM `" . $table . "` "
			. "WHERE option_name = " . $boundName . " LIMIT 1");

		// get_var() answers null for "no such row" and for "the statement was
		// refused", and every other statement in this class tells those two
		// apart. Returning '' for both is still the right ANSWER -- a caller
		// reads it as "no holder" and then re-attempts the atomic claim, which
		// a live holder's row still refuses, so an unreadable row can never
		// hand out a second copy of a lock. What it must not do is happen
		// silently: a host that refuses this SELECT refuses the claim next to
		// it too, which stops every synchronized section on the site with
		// nothing anywhere saying why.
		if ($value === null) {
			$lastError = $this->stringPropertyOf($wpdb, 'last_error');
			if ($lastError !== null && $lastError !== '') {
				$this->logStorageFailure('read the options row "' . $optionName . '"', $wpdb);
			}
		}

		return is_string($value) ? $value : '';
	}

	/** Give up $optionName unconditionally.
	 *
	 * @param string $optionName
	 * @return bool true if a row was removed.
	 */
	public function release($optionName) {
		return $this->deleteRow($optionName, null);
	}

	/** Give up $optionName, but only while it still records $value.
	 *
	 * Callers that mint a unique value per claim should prefer this: it makes
	 * "delete a row somebody else now holds" impossible rather than merely
	 * unlikely, which matters because every release decision is made on the
	 * strength of a read that happened earlier.
	 *
	 * @param string $optionName
	 * @param string $value
	 * @return bool true if the row recording $value was removed.
	 */
	public function releaseIfValueIs($optionName, $value) {
		return $this->deleteRow($optionName, (string)$value);
	}

	/**
	 * @param string $optionName
	 * @param string|null $requiredValue null deletes whatever is there.
	 * @return bool
	 */
	private function deleteRow($optionName, $requiredValue) {
		$wpdb = $this->wpdbOrNull();
		$table = $wpdb === null ? '' : $this->optionsTable($wpdb);
		if ($wpdb === null || $table === '') {
			return false;
		}

		$boundName = $this->bind($wpdb, '%s', array($optionName));
		if ($boundName === '') {
			return false;
		}
		$sql = "DELETE FROM `" . $table . "` WHERE option_name = " . $boundName;

		if ($requiredValue !== null) {
			$boundValue = $this->bind($wpdb, '%s', array($requiredValue));
			if ($boundValue === '') {
				return false;
			}
			$sql .= " AND option_value = " . $boundValue;
		}

		// DAO-bypass-approved: releasing a lock must not itself need one, and this runs in finally blocks and on shutdown, after the DAO may already have been torn down.
		$rowsDeleted = $wpdb->query($sql);

		// Same reasoning as claim(): removing no row is the ordinary outcome of
		// a conditional release whose row somebody else now holds, while a
		// refused statement means a held lock can never be given back, and the
		// two must not read the same way in the log.
		if ($rowsDeleted === false) {
			$this->logStorageFailure('release the options row "' . $optionName . '"', $wpdb);
			return false;
		}

		return ((int)$rowsDeleted) > 0;
	}

	/** Report a statement the database refused.
	 *
	 * Deliberately a warning rather than an error: a lock that cannot be taken
	 * stops synchronized WORK from running, which the plugin degrades past, and
	 * the site admin should not be emailed about their host's read-only replica.
	 * It still has to appear, because the alternative is a plugin that quietly
	 * stops rebuilding anything with nothing anywhere saying why.
	 *
	 * @param string $attempted what the statement was trying to do
	 * @param \wpdb $wpdb the handle that refused it
	 * @return void
	 */
	private function logStorageFailure($attempted, $wpdb) {
		if (!function_exists('abj_service')) {
			return;
		}

		$logger = abj_service('logging');
		if (!is_object($logger) || !method_exists($logger, 'warn')) {
			return;
		}

		$lastError = $this->stringPropertyOf($wpdb, 'last_error');
		$logger->warn('Could not ' . $attempted . '; treating the lock as unavailable. '
			. 'Database error: ' . ($lastError === null || $lastError === '' ? '(none reported)' : $lastError));
	}

	/** Bind $values into $fragment and hand back the escaped SQL text.
	 *
	 * Only VALUES are ever passed through wpdb::prepare(); the table name is
	 * concatenated by the caller, after optionsTable() has reduced it to
	 * identifier characters. prepare() has no way to escape an identifier
	 * before WordPress 6.2's %i, and this plugin supports 5.0, so feeding it a
	 * query with the table already interpolated would be asking it to vouch for
	 * something it never checked. Splitting the two makes which half is bound
	 * and which half is validated visible at every call site.
	 *
	 * @param \wpdb $wpdb
	 * @param literal-string $fragment placeholders only, no identifiers
	 * @param array<int, string> $values
	 * @return string '' when wpdb declined to bind, which aborts the statement.
	 */
	private function bind($wpdb, $fragment, array $values) {
		// Spread rather than passing $values as one array argument: both forms
		// are valid for wpdb::prepare(), but the variadic one is what every
		// call site in the wild uses and therefore the only one a replacement
		// handle can be relied on to implement.
		// DAO-bypass-approved: value binding for the statements above; escaping has to happen on the same handle that will execute them.
		$prepared = $wpdb->prepare($fragment, ...array_values($values));

		return is_string($prepared) ? $prepared : '';
	}

	/** The options table this instance's scope resolves to, reduced to
	 * characters legal in an identifier, or '' when it cannot be resolved.
	 *
	 * The parameter is typed in the docblock only. A native hint would enforce
	 * `instanceof wpdb` at runtime and reject any drop-in handle that does not
	 * extend core's class, which is a narrowing this code has no reason to
	 * impose: everything it needs is the three methods wpdbOrNull() confirmed.
	 *
	 * @param \wpdb $wpdb the handle wpdbOrNull() already validated
	 * @return string
	 */
	private function optionsTable($wpdb) {
		if ($this->scope === self::SCOPE_NETWORK_MAIN_SITE) {
			$prefix = $this->stringPropertyOf($wpdb, 'base_prefix');
			if ($prefix === null) {
				// Fail closed rather than quietly resolving a NETWORK-wide
				// claim to the current blog's table, which would narrow its
				// scope without anything reporting that it had.
				return '';
			}
			if (function_exists('get_main_site_id') && $this->canCall($wpdb, 'get_blog_prefix')) {
				$mainSitePrefix = $wpdb->get_blog_prefix((int)get_main_site_id());
				if (is_string($mainSitePrefix) && $mainSitePrefix !== '') {
					$prefix = $mainSitePrefix;
				}
			}
			return $this->asIdentifier($prefix . 'options');
		}

		$table = $this->stringPropertyOf($wpdb, 'options');
		if ($table !== null && $table !== '') {
			return $this->asIdentifier($table);
		}

		$prefix = $this->stringPropertyOf($wpdb, 'prefix');
		if ($prefix === null) {
			return '';
		}

		// An empty $table_prefix is unusual but legal, so this branch can
		// legitimately produce the bare table name 'options'.
		return $this->asIdentifier($prefix . 'options');
	}

	/** The WordPress database handle, or null when there is not a usable one.
	 *
	 * Callers read a null handle as "not claimed" and "no value", which stops
	 * work rather than running it without exclusion.
	 *
	 * @return \wpdb|null
	 */
	private function wpdbOrNull() {
		global $wpdb;
		// PHPStan has no type for a WordPress global, so it sees `mixed` here
		// and every later narrowing widens to a bare `object`. This states the
		// one production reality -- core's wpdb, or a drop-in such as HyperDB
		// that extends it -- rather than overriding anything PHPStan inferred,
		// and mirrors how DatabaseQueryExecutor::prepareQueryParameters()
		// reaches the same global. The runtime checks below still do the real
		// work, because a test double is not an instanceof wpdb.
		/** @var \wpdb $wpdb */

		if (!is_object($wpdb)) {
			return null;
		}

		foreach (array('prepare', 'query', 'get_var') as $method) {
			if (!$this->canCall($wpdb, $method)) {
				return null;
			}
		}

		return $wpdb;
	}

	/** Whether $method can be invoked on $object, counting methods reached
	 * through __call() and not only declared ones.
	 *
	 * method_exists() alone answers "no" for anything routed through __call(),
	 * which is how WordPress drop-in database handles and this suite's $wpdb
	 * doubles expose most of their surface. Reading that "no" as "there is no
	 * usable database handle" would silently disable every claim on such a
	 * site.
	 *
	 * @param object $object
	 * @param string $method
	 * @return bool
	 */
	private function canCall($object, $method) {
		return method_exists($object, $method) || method_exists($object, '__call');
	}

	/** A string property of the database handle, or null when it is absent or
	 * is not a string.
	 *
	 * isset() and ?? are deliberately not used: both consult __isset(), which
	 * objects exposing their fields through __get() do not necessarily
	 * implement.
	 *
	 * @param object $object
	 * @param string $name
	 * @return string|null
	 */
	private function stringPropertyOf($object, $name) {
		if (!property_exists($object, $name) && !method_exists($object, '__get')) {
			return null;
		}

		$value = $object->$name;

		return is_string($value) ? $value : null;
	}

	/** Reduce a resolved table name to characters legal in an identifier.
	 *
	 * @param string $table
	 * @return string
	 */
	private function asIdentifier($table) {
		return (string)preg_replace('/[^A-Za-z0-9_]/', '', $table);
	}
}
