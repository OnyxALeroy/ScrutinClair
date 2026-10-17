(function () {
    'use strict';

    var form = document.querySelector('form.search');
    if (!form) {
        return;
    }

    var input = form.querySelector('#texte-search');
    var list = form.querySelector('#texte-suggestions');
    if (!input || !list) {
        return;
    }

    var apiUrl = form.getAttribute('data-textes-api') || '/api/textes';
    var urlBase = form.getAttribute('data-texte-url-base') || '/textes/__id__';
    var activeIndex = -1;
    var debounceTimer = null;

    function buildUrl(id) {
        return urlBase.replace('__id__', encodeURIComponent(id));
    }

    function closeList() {
        list.innerHTML = '';
        activeIndex = -1;
        input.setAttribute('aria-expanded', 'false');
    }

    function renderItems(textes) {
        list.innerHTML = '';
        activeIndex = -1;

        textes.forEach(function (texte) {
            var li = document.createElement('li');
            li.setAttribute('role', 'option');

            var a = document.createElement('a');
            a.href = buildUrl(texte.id);

            var titre = document.createElement('span');
            titre.textContent = texte.titre || 'Sans titre';
            a.appendChild(titre);
            li.appendChild(a);

            if (texte.synthese) {
                var synthese = document.createElement('span');
                synthese.className = 'synthese';
                synthese.textContent = texte.synthese;
                li.appendChild(synthese);
            }

            list.appendChild(li);
        });

        input.setAttribute('aria-expanded', textes.length > 0 ? 'true' : 'false');
    }

    function highlight() {
        var links = list.querySelectorAll('a');
        links.forEach(function (link, index) {
            link.classList.toggle('active', index === activeIndex);
        });
        if (links[activeIndex]) {
            links[activeIndex].scrollIntoView({ block: 'nearest' });
        }
    }

    function fetchSuggestions(query) {
        fetch(apiUrl + '?q=' + encodeURIComponent(query))
            .then(function (response) {
                return response.ok ? response.json() : [];
            })
            .then(function (textes) {
                if (input.value.trim() !== query) {
                    return;
                }
                renderItems(Array.isArray(textes) ? textes : []);
            })
            .catch(closeList);
    }

    input.addEventListener('input', function () {
        var query = input.value.trim();

        window.clearTimeout(debounceTimer);

        if (query === '') {
            closeList();
            return;
        }

        debounceTimer = window.setTimeout(function () {
            fetchSuggestions(query);
        }, 200);
    });

    input.addEventListener('keydown', function (event) {
        var links = list.querySelectorAll('a');

        if (event.key === 'ArrowDown' && links.length > 0) {
            event.preventDefault();
            activeIndex = (activeIndex + 1) % links.length;
            highlight();
        } else if (event.key === 'ArrowUp' && links.length > 0) {
            event.preventDefault();
            activeIndex = (activeIndex - 1 + links.length) % links.length;
            highlight();
        } else if (event.key === 'Enter' && activeIndex >= 0 && links[activeIndex]) {
            event.preventDefault();
            window.location.href = links[activeIndex].getAttribute('href');
        } else if (event.key === 'Escape') {
            closeList();
        }
    });

    input.addEventListener('blur', function () {
        window.setTimeout(closeList, 150);
    });

    document.addEventListener('click', function (event) {
        if (!form.contains(event.target)) {
            closeList();
        }
    });
})();
