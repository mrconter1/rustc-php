// stdout: hello
// stdout: hello
fn print_it(s: &String) {
    println!("{}", s);
}

fn main() {
    let s = String::from("hello");
    print_it(&s);
    print_it(&s);
}
