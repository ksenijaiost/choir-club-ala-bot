<?php

declare(strict_types=1);

namespace App\Repositories;

final class SongRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public static function normalizeTitle(string $title): string
    {
        return mb_strtolower(trim($title), 'UTF-8');
    }

    public function create(string $title): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO songs (title, title_normalized) VALUES (?, ?)'
        );
        $stmt->execute([trim($title), self::normalizeTitle($title)]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM songs WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecent(int $limit = 25): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM songs ORDER BY title_normalized ASC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchByTitleFragment(string $query, int $limit = 10): array
    {
        $norm = '%' . self::normalizeTitle($query) . '%';
        $stmt = $this->pdo->prepare(
            'SELECT * FROM songs WHERE title_normalized LIKE ? ORDER BY title_normalized ASC LIMIT ?'
        );
        $stmt->bindValue(1, $norm);
        $stmt->bindValue(2, $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    public function countWithCurrentMaterials(): int
    {
        $stmt = $this->pdo->query(
            'SELECT COUNT(DISTINCT s.id) FROM songs s
             INNER JOIN materials m ON m.song_id = s.id AND m.is_current = 1'
        );

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listWithCurrentMaterials(int $offset, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT s.* FROM songs s
             INNER JOIN materials m ON m.song_id = s.id AND m.is_current = 1
             ORDER BY s.title_normalized ASC
             LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }
}
