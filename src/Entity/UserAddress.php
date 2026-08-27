<?php
declare(strict_types=1);

namespace App\Entity;

use App\Enum\AddressType;
use App\Repository\UserAddressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserAddressRepository::class)]
#[ORM\Table(name: 'user_addresses')]
#[ORM\Index(name: 'idx_user_address_user', fields: ['user_id'])]
#[ORM\Index(name: 'idx_user_address_type', fields: ['address_type'])]
#[ORM\Index(name: 'idx_user_address_primary', fields: ['is_primary'])]
class UserAddress extends AbstractEntity
{
    #[ORM\Column(type: 'uuid')]
    private ?Uuid $userId = null;

    #[ORM\Column(length: 20)]
    private string $addressType = AddressType::Current->value;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $addressLine1;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $addressLine2 = null;

    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100)]
    private string $city;

    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $district = null;

    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100)]
    private string $state;

    #[Assert\Length(max: 100)]
    #[ORM\Column(length: 100)]
    private string $country;

    #[Assert\Length(max: 20)]
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isPrimary = false;

    public function __construct()
    {
        parent::__construct();
    }

    public function getUserId(): ?Uuid
    {
        return $this->userId;
    }

    public function setUserId(?Uuid $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function getAddressType(): AddressType
    {
        return AddressType::from($this->addressType);
    }

    public function setAddressType(AddressType $addressType): self
    {
        $this->addressType = $addressType->value;

        return $this;
    }

    public function getAddressLine1(): string
    {
        return $this->addressLine1;
    }

    public function setAddressLine1(string $addressLine1): self
    {
        $this->addressLine1 = trim($addressLine1);

        return $this;
    }

    public function getAddressLine2(): ?string
    {
        return $this->addressLine2;
    }

    public function setAddressLine2(?string $addressLine2): self
    {
        $this->addressLine2 = $addressLine2 !== null ? trim($addressLine2) : null;

        return $this;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function setCity(string $city): self
    {
        $this->city = trim($city);

        return $this;
    }

    public function getDistrict(): ?string
    {
        return $this->district;
    }

    public function setDistrict(?string $district): self
    {
        $this->district = $district !== null ? trim($district) : null;

        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): self
    {
        $this->state = trim($state);

        return $this;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function setCountry(string $country): self
    {
        $this->country = trim($country);

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): self
    {
        $this->postalCode = $postalCode !== null ? trim($postalCode) : null;

        return $this;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function setIsPrimary(bool $isPrimary): self
    {
        $this->isPrimary = $isPrimary;

        return $this;
    }
}
