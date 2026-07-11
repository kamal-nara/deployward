<?php

namespace Deployward\Config;

final class Deployment
{
    const TYPES = array('plugin', 'theme', 'mu-plugin');
    const VISIBILITIES = array('public', 'private');
    const POLL_INTERVALS = array(5, 15, 30, 60);

    /** @var string */
    private $id;
    /** @var string */
    private $repo;
    /** @var string */
    private $branch;
    /** @var string */
    private $visibility;
    /** @var string */
    private $targetType;
    /** @var string */
    private $targetSlug;
    /** @var string */
    private $token;
    /** @var string */
    private $webhookSecret;
    /** @var string */
    private $lastDeployedSha;
    /** @var bool */
    private $autoDeploy;
    /** @var int */
    private $pollInterval;

    public function __construct(
        string $id,
        string $repo,
        string $branch,
        string $visibility,
        string $targetType,
        string $targetSlug,
        string $token,
        string $webhookSecret,
        string $lastDeployedSha,
        bool $autoDeploy = false,
        int $pollInterval = 5
    ) {
        $this->id = $id;
        $this->repo = $repo;
        $this->branch = $branch;
        $this->visibility = $visibility;
        $this->targetType = $targetType;
        $this->targetSlug = $targetSlug;
        $this->token = $token;
        $this->webhookSecret = $webhookSecret;
        $this->lastDeployedSha = $lastDeployedSha;
        $this->autoDeploy = $autoDeploy;
        $this->pollInterval = $pollInterval;
    }

    public static function normalizeRepo(string $input): string
    {
        $repo = trim($input);
        if (preg_match('#git@github\.com:(.+)$#i', $repo, $m)) {
            $repo = $m[1];
        }
        $repo = preg_replace('#^https?://#i', '', $repo);
        $repo = preg_replace('#^(www\.)?github\.com/#i', '', $repo);
        $repo = preg_replace('~[?#].*$~', '', $repo);
        $repo = preg_replace('#\.git$#i', '', $repo);
        $repo = trim($repo, '/');
        $parts = array_values(array_filter(explode('/', $repo), function ($segment) {
            return $segment !== '';
        }));
        if (count($parts) >= 2) {
            return $parts[0] . '/' . $parts[1];
        }
        return $repo;
    }

    private static function deriveSlug(string $repo): string
    {
        $name = $repo;
        if (strpos($repo, '/') !== false) {
            $parts = explode('/', $repo);
            $name = (string) end($parts);
        }
        $slug = preg_replace('/[^A-Za-z0-9_-]/', '-', $name);
        return trim($slug, '-');
    }

    public static function fromArray(array $data): self
    {
        foreach (array('id', 'repo', 'branch', 'visibility', 'target_type') as $key) {
            if (! isset($data[$key]) || $data[$key] === '') {
                throw new \InvalidArgumentException('Missing required field: ' . $key);
            }
        }
        $repo = self::normalizeRepo((string) $data['repo']);
        if (! preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) {
            throw new \InvalidArgumentException('repo must be a GitHub owner/repo or URL');
        }
        if (! in_array($data['visibility'], self::VISIBILITIES, true)) {
            throw new \InvalidArgumentException('invalid visibility');
        }
        if (! in_array($data['target_type'], self::TYPES, true)) {
            throw new \InvalidArgumentException('invalid target_type');
        }
        $slug = isset($data['target_slug']) ? (string) $data['target_slug'] : '';
        if ($slug === '') {
            $slug = self::deriveSlug($repo);
        }
        if (! preg_match('#^[A-Za-z0-9][A-Za-z0-9_-]*$#', $slug)) {
            throw new \InvalidArgumentException('invalid target_slug');
        }
        $auto = filter_var(isset($data['auto_deploy']) ? $data['auto_deploy'] : false, FILTER_VALIDATE_BOOLEAN);
        $interval = isset($data['poll_interval']) ? (int) $data['poll_interval'] : 5;
        if (! in_array($interval, self::POLL_INTERVALS, true)) {
            throw new \InvalidArgumentException('invalid poll_interval');
        }

        return new self(
            (string) $data['id'],
            $repo,
            (string) $data['branch'],
            (string) $data['visibility'],
            (string) $data['target_type'],
            $slug,
            isset($data['token']) ? (string) $data['token'] : '',
            isset($data['webhook_secret']) ? (string) $data['webhook_secret'] : '',
            isset($data['last_deployed_sha']) ? (string) $data['last_deployed_sha'] : '',
            $auto,
            $interval
        );
    }

    public function toArray(): array
    {
        return array(
            'id' => $this->id,
            'repo' => $this->repo,
            'branch' => $this->branch,
            'visibility' => $this->visibility,
            'target_type' => $this->targetType,
            'target_slug' => $this->targetSlug,
            'token' => $this->token,
            'webhook_secret' => $this->webhookSecret,
            'last_deployed_sha' => $this->lastDeployedSha,
            'auto_deploy' => $this->autoDeploy,
            'poll_interval' => $this->pollInterval,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function repo(): string
    {
        return $this->repo;
    }

    public function branch(): string
    {
        return $this->branch;
    }

    public function visibility(): string
    {
        return $this->visibility;
    }

    public function targetType(): string
    {
        return $this->targetType;
    }

    public function targetSlug(): string
    {
        return $this->targetSlug;
    }

    public function token(): string
    {
        return $this->token;
    }

    public function webhookSecret(): string
    {
        return $this->webhookSecret;
    }

    public function lastDeployedSha(): string
    {
        return $this->lastDeployedSha;
    }

    public function isAutoDeployEnabled(): bool
    {
        return $this->autoDeploy;
    }

    public function pollInterval(): int
    {
        return $this->pollInterval;
    }

    public function withLastDeployedSha(string $sha): self
    {
        $next = $this->toArray();
        $next['last_deployed_sha'] = $sha;

        return self::fromArray($next);
    }

    public function withToken(string $token): self
    {
        $next = $this->toArray();
        $next['token'] = $token;

        return self::fromArray($next);
    }
}
