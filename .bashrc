# ~/.bashrc: executed by bash(1) for non-login shells.
# see /usr/share/doc/bash/examples/startup-files (in the package bash-doc)
# for examples

# If not running interactively, don't do anything
case $- in
    *i*) ;;
      *) return;;
esac

# don't put duplicate lines or lines starting with space in the history.
# See bash(1) for more options
HISTCONTROL=ignoreboth

case "$SHELL" in
*/bash)
    # append to the history file, don't overwrite it
    shopt -s histappend

    # for setting history length see HISTSIZE and HISTFILESIZE in bash(1)
    HISTSIZE=1000
    HISTFILESIZE=2000

    # check the window size after each command and, if necessary,
    # update the values of LINES and COLUMNS.
    shopt -s checkwinsize

    # If set, the pattern "**" used in a pathname expansion context will
    # match all files and zero or more directories and subdirectories.
    #shopt -s globstar

    # enable programmable completion features (you don't need to enable
    # this, if it's already enabled in /etc/bash.bashrc and /etc/profile
    # sources /etc/bash.bashrc).

    if ! shopt -oq posix; then
      if [ -f /usr/share/bash-completion/bash_completion ]; then
        . /usr/share/bash-completion/bash_completion
      elif [ -f /etc/bash_completion ]; then
        . /etc/bash_completion
      fi
    fi

	;;
esac

# make less more friendly for non-text input files, see lesspipe(1)
[ -x /usr/bin/lesspipe ] && eval "$(SHELL=/bin/sh lesspipe)"

# set variable identifying the chroot you work in (used in the prompt below)
if [ -z "${debian_chroot:-}" ] && [ -r /etc/debian_chroot ]; then
    debian_chroot=$(cat /etc/debian_chroot)
fi

# set a fancy prompt (non-color, unless we know we "want" color)
case "$TERM" in
    xterm-color|*-256color) color_prompt=yes;;
esac

# uncomment for a colored prompt, if the terminal has the capability; turned
# off by default to not distract the user: the focus in a terminal window
# should be on the output of commands, not on the prompt
#force_color_prompt=yes

if [ -n "$force_color_prompt" ]; then
    if [ -x /usr/bin/tput ] && tput setaf 1 >&/dev/null; then
	# We have color support; assume it's compliant with Ecma-48
	# (ISO/IEC-6429). (Lack of such support is extremely rare, and such
	# a case would tend to support setf rather than setaf.)
	color_prompt=yes
    else
	color_prompt=
    fi
fi

# Add an "alert" alias for long running commands.  Use like so:
#   sleep 10; alert
alias alert='notify-send --urgency=low -i "$([ $? = 0 ] && echo terminal || echo error)" "$(history|tail -n1|sed -e '\''s/^\s*[0-9]\+\s*//;s/[;&|]\s*alert$//'\'')"'

# Alias definitions.
# You may want to put all your additions into a separate file like
# ~/.bash_aliases, instead of adding them here directly.
# See /usr/share/doc/bash-doc/examples in the bash-doc package.

if [ -f ~/.bash_aliases ]; then
    . ~/.bash_aliases
fi


set -o vi
set -o notify
export EDITOR=vim

CDPATH="\
.:\
/var:\
/var/spool:\
/etc:\
"; export CDPATH

function set_title() {
	echo -ne "\033]0;${1}\007"
}

case "$SHELL" in
*/bash)
	command_oriented_history=1
	back() { cd - ${1+"$@"}; }
	h() { fc -l ${1+"$@"}; }
	eval 'functions() { typeset -f ${1+"$@"}; }'
	whence() { type -path ${1+"$@"}; }
	cdl(){
		awk '{for (i=0; i<NF; i++) print i, $(i+1)}' /tmp/.cd$$|head -8
	}
	cd(){
		case $1 in
			-) pushd;;
			-[1-9]) pushd +`echo $1|cut -c2`;;
			'') pushd $HOME;;
			*) pushd "$@";;
		esac > /tmp/.cd$$
		return
	}
	touch /tmp/.cd$$
    chown `id -u` /tmp/.cd$$
	;;
esac

export LESS="eFRX"

alias pp='ps -ef'
alias l='ls -la'
alias gzcat='gunzip -c'
alias pgr='pp|grep "$@"'
alias ll='l|more'
alias ldir='l|grep "^d"'
alias c='printf "\\33c"'
alias cl='c;l'
alias .p=". $HOME/.bashrc"
alias gs='git status -uno'
alias gd='git diff'
alias gl='git log'
alias title=set_title
alias wdata='su -s /bin/bash -l www-data'
alias vault='/var/www/site/artisan  --yes vault:Admin Status admin'

grep -q "www-data" /etc/passwd
if [ $? -eq 0 ]
then
    host="app";
fi

Prompt='$'; [ `id -u` -eq 0 ] && Prompt='#'
export PS1="$host["`id -un`"]:\w$Prompt "

title "$host["`id -un`"]"

alarm() { perl -e 'alarm shift; exec @ARGV' "$@"; }

if [ $host == "app" ]
then
    cd /var/www/site
fi

