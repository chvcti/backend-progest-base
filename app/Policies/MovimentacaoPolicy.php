<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Movimentacao;
use Illuminate\Auth\Access\HandlesAuthorization;

class MovimentacaoPolicy
{
    use HandlesAuthorization;

    /**
     * Determina se o usuário pode processar (aprovar ou reprovar) a movimentação de estoque.
     *
     * @param  \App\Models\User  $user
     * @param  \App\Models\Movimentacao  $movimentacao
     * @return mixed
     */
    public function processar(User $user, Movimentacao $movimentacao)
    {
        // Se for SuperAdmin, tem acesso global
        if ($user->isSuperAdmin()) {
            return true;
        }

        $isDevolucao = ($movimentacao->tipo === 'D');

        // Em devoluções, o acesso de aprovação é do setor de destino (que recebe de volta)
        // Em saídas/transferências, o acesso é do setor de origem (quem vai fornecer o material)
        $setorAlvoId = $isDevolucao 
            ? $movimentacao->setor_destino_id 
            : $movimentacao->setor_origem_id;

        // Utiliza o relacionamento Eloquent para validar o vínculo e o perfil do usuário
        return $user->setores()
            ->where('setores.id', $setorAlvoId)
            ->whereIn('usuario_setor.perfil', ['admin', 'almoxarife'])
            ->exists();
    }
}
