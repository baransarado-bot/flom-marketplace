document.querySelectorAll('[data-flores-timur-location]').forEach((container) => {
    const regions = JSON.parse(container.dataset.regions);
    const districtSelect = container.querySelector('[data-location-district]');
    const villageSelect = container.querySelector('[data-location-village]');

    if (!districtSelect || !villageSelect) {
        throw new Error('Form lokasi Flores Timur harus memiliki pilihan kecamatan dan desa/kelurahan.');
    }

    districtSelect.addEventListener('change', () => {
        villageSelect.replaceChildren(new Option('Pilih desa/kelurahan', ''));
        const villages = regions[districtSelect.value] || [];
        villages.forEach((village) => {
            const label = village.toLocaleLowerCase('id-ID').replace(/\b\p{L}/gu, (letter) => letter.toLocaleUpperCase('id-ID'));
            villageSelect.add(new Option(label, village));
        });
        villageSelect.disabled = villages.length === 0;
    });
});
