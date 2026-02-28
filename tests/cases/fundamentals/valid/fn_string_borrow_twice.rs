// stdout: hello
// stdout: hello
// stdout: hello
fn say(s: &String) {
    println!("{}", s);
}

fn main() {
    let s = String::from("hello");
    say(&s);
    say(&s);
    println!("{}", s);
}
