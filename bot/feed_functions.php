<?php

/*
 * Copyright (C) 2015 Jacobi Carter and Chris Breneman
 *
 * This file is part of ClueBot NG.
 *
 * ClueBot NG is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * ClueBot NG is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ClueBot NG.  If not, see <http://www.gnu.org/licenses/>.
 */
    class Feed
    {
        public static $host = 'irc.wikimedia.org';
        public static $port = 6667;
        public static $channel = '#en.wikipedia';
        private static $fd;
        public static function connectLoop()
        {
            self::$fd = fsockopen(self::$host, self::$port, $feederrno, $feederrstr, 30);
            if (!self::$fd) {
                return;
            }
            $nick = str_replace(' ', '_', config::$user);
            self::send('USER '.$nick.' "1" "1" :ClueBot Wikipedia Bot 2.0.');
            self::send('NICK '.$nick);
            while (!feof(self::$fd)) {
                $rawline = fgets(self::$fd, 1024);
                $line = str_replace(array("\n", "\r"), '', $rawline);
                if (!$line) {
                    fclose(self::$fd);
                    break;
                }
                self::loop($line);
            }
        }
        private static function loop($line)
        {
            $d = IRC::split($line);
            if ($d === null) {
                return;
            }
            if ($d[ 'type' ] == 'direct') {
                switch ($d[ 'command' ]) {
                    case 'ping':
                        self::send('PONG :'.$d[ 'pieces' ][ 0 ]);
                        break;
                }
            } else {
                switch ($d[ 'command' ]) {
                    case '376':
                    case '422':
                        self::send('JOIN '.self::$channel);
                        break;
                    case 'privmsg':
                        if (strtolower($d[ 'target' ]) == self::$channel) {
                            $rawmessage = $d[ 'pieces' ][ 0 ];
                            $message = str_replace("\002", '', $rawmessage);
                            $message = preg_replace('/\003(\d\d?(,\d\d?)?)?/', '', $message);
                            $data = parseFeed($message);

                            if ($data === false) {
                                return;
                            }

                            if (stripos('N', $data[ 'flags' ]) !== false) {
                                Relay::skippedEdit($data, 'new_arcticle');

                                return;
                            }

                            Relay::stalkEdit($data);
                            switch ($data[ 'namespace' ].$data[ 'title' ]) {
                                case 'User:'.config::$user.'/Run':
                                    globals::$run = API::$q->getpage('User:'.config::$user.'/Run');
                                    break;
                                case 'Wikipedia:Huggle/Whitelist';
                                    globals::$wl = API::$q->getpage('Wikipedia:Huggle/Whitelist');
                                    break;
                                case 'User:'.config::$user.'/Optin':
                                    globals::$optin = API::$q->getpage('User:'.config::$user.'/Optin');
                                    break;
                                case 'User:'.config::$user.'/AngryOptin':
                                    globals::$aoptin = API::$q->getpage('User:'.config::$user.'/AngryOptin');
                                    break;
                            }
                            if (
                                ($data[ 'namespace' ] != 'Main:')
                                and ((!preg_match('/\* \[\[('.preg_quote($data[ 'namespace' ].$data[ 'title' ], '/').')\]\] \- .*/i', globals::$optin)))
                            ) {
                                self::bail($data, 'Outside of valid namespaces');

                                return;
                            }
                            echo 'Processing: '.$message."\n";
                            Process::processEdit($data);
                        }
                        break;
                }
            }
        }
        public static function send($line)
        {
            fwrite(self::$fd, $line."\n");
        }
    }
