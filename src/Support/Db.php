<?php
declare(strict_types=1);

namespace Grube\Price30\Support;

/**
 * Datenbankzugriff mit erzwungenem Tabellenpräfix.
 *
 * Auf diesem Webspace teilen sich **alle Projekte und beide Umgebungen** eine einzige
 * MySQL-Datenbank; getrennt wird ausschließlich über den Tabellennamen
 * (`y5x_prod_*` / `y5x_stg_*`). Deshalb gibt es hier keinen Weg zu einer Tabelle ohne
 * Präfix: {@see table} kennt nur die bekannten Namen, und {@see query} weist eine
 * Abfrage mit nacktem Tabellennamen zurück.
 *
 * Bei diesem Werkzeug wiegt das schwerer als sonst: Ein Staging-Lauf, der in die
 * Produktionstabellen schriebe, würde die Beweisgrundlage nach § 11 PAngV verfälschen.
 */
final class Db
{
    private const TABLES = ['price_events', 'price_state', 'run_log', 'run_issue', 'pss_write_log', 'users', 'login_log', 'article_meta', 'invitations'];

    private ?\PDO $pdo = null;

    public function __construct(
        private readonly array $credentials,
        public readonly string $env,
    ) {
    }

    public static function fromRuntime(string $runtimeDir): self
    {
        $env = \trim((string) @\file_get_contents($runtimeDir . '/ENV')) ?: 'staging';
        $pfad = $runtimeDir . '/db.php';
        if (!\is_readable($pfad)) {
            throw new \RuntimeException("DB-Zugang nicht lesbar: $pfad — 'bash deploy.sh $env' ausfuehren.");
        }
        return new self(require $pfad, $env);
    }

    public function prefix(): string
    {
        return 'y5x_' . ($this->env === 'prod' ? 'prod' : 'stg') . '_';
    }

    public function table(string $name): string
    {
        if (!\in_array($name, self::TABLES, true)) {
            throw new \InvalidArgumentException("unbekannte Tabelle: $name");
        }
        return $this->prefix() . $name;
    }

    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $c = $this->credentials;
            $this->pdo = new \PDO(
                "mysql:host={$c['host']};dbname={$c['name']};charset=" . ($c['charset'] ?? 'utf8mb4'),
                $c['user'], $c['pass'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                 \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                 \PDO::ATTR_EMULATE_PREPARES => false]);
        }
        return $this->pdo;
    }

    /** `{p}price_events` -> `y5x_stg_price_events`. Nackte Namen werden abgewiesen. */
    public function expand(string $sql): string
    {
        $out = \str_replace('{p}', $this->prefix(), $sql);
        foreach (self::TABLES as $t) {
            if (\preg_match('/(?<![a-z0-9_])' . \preg_quote($t, '/') . '(?![a-z0-9_])/i', $out)
                && !\str_contains($out, $this->prefix() . $t)) {
                throw new \LogicException(
                    "Tabelle ohne Praefix in der Query: $t. Auf diesem Webspace trennt NUR "
                    . "der Praefix Staging von Produktion — schreib {p}$t.");
            }
        }
        return $out;
    }

    public function query(string $sql, array $args = []): array
    {
        $st = $this->pdo()->prepare($this->expand($sql));
        $st->execute($args);
        return $st->fetchAll();
    }

    public function one(string $sql, array $args = []): ?array
    {
        return $this->query($sql, $args)[0] ?? null;
    }

    public function execute(string $sql, array $args = []): int
    {
        $st = $this->pdo()->prepare($this->expand($sql));
        $st->execute($args);
        return $st->rowCount();
    }
}
