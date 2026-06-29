<?php

namespace Deployward\Config;

final class Deployment
{
    const TYPES = array('plugin', 'theme', 'mu-plugin');
    const VISIBILITIES = array('public', 'private');

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

    public function __construct(
        string $id,
        string $repo,
        string $branch,
        string $visibility,
        string $targetType,
        string $targetSlug,
        string $token,
        string $webhookSecret,
        string $lastDeployedSha
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
    }

    public static function fromArray(array $data): self
    {
        foreach (array('id', 'repo', 'branch', 'visibility', 'target_type', 'target_slug') as $key) {
            if (! isset($data[$key]) || $data[$key] === '') {
                throw new \InvalidArgumentException('Missing required field: ' . $key);
            }
        }
        if (! preg_match('#^[\w.-]+/[\w.-]+$#', $data['repo'])) {
            throw new \InvalidArgumentException('repo must be in owner/repo form');
        }
        if (! in_array($data['visibility'], self::VISIBILITIES, true)) {
            throw new \InvalidArgumentException('invalid visibility');
        }
        if (! in_array($data['target_type'], self::TYPES, true)) {
            throw new \InvalidArgumentException('invalid target_type');
        }
        if (! preg_match('#^[A-Za-z0-9][A-Za-z0-9_-]*$#', $data['target_slug'])) {
            throw new \InvalidArgumentException('invalid target_slug');
        }

        return new self(
            (string) $data['id'],
            (string) $data['repo'],
            (string) $data['branch'],
            (string) $data['visibility'],
            (string) $data['target_type'],
            (string) $data['target_slug'],
            isset($data['token']) ? (string) $data['token'] : '',
            isset($data['webhook_secret']) ? (string) $data['webhook_secret'] : '',
            isset($data['last_deployed_sha']) ? (string) $data['last_deployed_sha'] : ''
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
