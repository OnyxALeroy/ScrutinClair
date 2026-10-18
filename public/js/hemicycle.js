(function () {
    'use strict';

    function ligne(texte, valeur, className) {
        var row = document.createElement('span');
        row.className = 'tip-row ' + className;

        var label = document.createElement('span');
        label.textContent = texte;

        var nombre = document.createElement('b');
        nombre.textContent = valeur;

        row.appendChild(label);
        row.appendChild(nombre);

        return row;
    }

    function remplir(tip, donnees) {
        tip.textContent = '';

        var parti = document.createElement('span');
        parti.className = 'tip-parti';
        parti.textContent = donnees.parti || 'Groupe';
        tip.appendChild(parti);

        if (donnees.partiComplet && donnees.partiComplet !== donnees.parti) {
            var nom = document.createElement('span');
            nom.className = 'tip-nom';
            nom.textContent = donnees.partiComplet;
            tip.appendChild(nom);
        }

        tip.appendChild(ligne('Pour', donnees.pour, 'tip-pour'));
        tip.appendChild(ligne('Contre', donnees.contre, 'tip-contre'));
        tip.appendChild(ligne('Abstentions', donnees.abstention, 'tip-abstention'));
        tip.appendChild(ligne('Total', donnees.total, 'tip-total'));
    }

    document.querySelectorAll('[data-hemicycle]').forEach(function (root) {
        var tip = root.querySelector('.hemicycle__tooltip');
        if (!tip) {
            return;
        }

        var groupes = root.querySelectorAll('.hemicycle__groupe');
        var actif = null;

        function positionner(event) {
            var rect = root.getBoundingClientRect();
            tip.style.left = (event.clientX - rect.left) + 'px';
            tip.style.top = (event.clientY - rect.top) + 'px';
        }

        function afficher(groupe, event) {
            if (actif !== groupe) {
                if (actif) {
                    actif.classList.remove('is-active');
                }
                actif = groupe;
                groupe.classList.add('is-active');
                root.classList.add('is-hovering');
                remplir(tip, groupe.dataset);
            }

            positionner(event);
            tip.classList.add('is-visible');
        }

        function masquer() {
            if (actif) {
                actif.classList.remove('is-active');
                actif = null;
            }
            root.classList.remove('is-hovering');
            tip.classList.remove('is-visible');
        }

        groupes.forEach(function (groupe) {
            groupe.addEventListener('mouseenter', function (event) {
                afficher(groupe, event);
            });
            groupe.addEventListener('mousemove', function (event) {
                afficher(groupe, event);
            });
        });

        root.addEventListener('mouseleave', masquer);
    });
})();
