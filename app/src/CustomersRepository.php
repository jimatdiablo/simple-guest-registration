<?php

class CustomersRepository
{
    private string $lastSourceTable = 'customers';

    public function __construct(private PDO $pdo)
    {
    }

    public function lastSourceTable(): string
    {
        return $this->lastSourceTable;
    }

    public function listByServiceGroups(array $serviceGroups, int $limit = 5000): array
    {
        if ($serviceGroups === []) {
            return [];
        }

        $limit = max(1, min($limit, 20000));
        $placeholders = implode(',', array_fill(0, count($serviceGroups), '?'));
        try {
            $sql = "SELECT * FROM customers WHERE sg IN ($placeholders) ORDER BY id ASC LIMIT $limit";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($serviceGroups);
            $this->lastSourceTable = 'customers';
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), '42S02')) {
                $sql = "SELECT * FROM modems WHERE sg IN ($placeholders) ORDER BY id ASC LIMIT $limit";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($serviceGroups);
                $this->lastSourceTable = 'modems';
                return $stmt->fetchAll();
            }

            throw $e;
        }
    }
}