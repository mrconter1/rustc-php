// exit: 55
// stdout: 55

enum Cmd {
    Add(i32),
    Reset,
}

fn main() {
    let mut total = 0;
    let mut i = 1;
    while i <= 10 {
        let cmd = Cmd::Add(i);
        match cmd {
            Cmd::Add(v) => { total = total + v; }
            Cmd::Reset  => { total = 0; }
        }
        i = i + 1;
    }
    println!("{}", total);
    exit(total);
}
